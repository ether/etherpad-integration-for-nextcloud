/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// Deletes pads' files past the trash, one WebDAV DELETE each, so their rows
// wait for the sweep, and then runs the sweep through the admin's "Check
// pending pads" (settle-pending) until no row waits: the job's work, at
// once rather than after its grace. It reports how long a delete took, and
// for each run of the sweep how long it took, the rows it took and the
// Etherpad calls it made. With --plans, Postgres explains the statements
// on the app's table meanwhile, which slows them: time a run without it.
// A folder deleted past the trash would do from Nextcloud 35.0.1, which
// has the fix for the ids a folder's files are reported under; earlier
// releases report them wrongly (docs/deleting-pads.md).
//
//   node tests/load/sweep.mjs --skip 2000 --pads 1000

import { readFileSync, writeFileSync } from 'node:fs'
import { parseArgs } from 'node:util'
import { appApi, eachLimited, etherpadCalls, loadTarget, repo, request, spread, sqlValue, withPlans } from './lib.mjs'

const { values: args } = parseArgs({
	options: {
		state: { type: 'string', default: `${repo}tests/load/.work/pads.json` },
		skip: { type: 'string', default: '0' },
		pads: { type: 'string', default: '1000' },
		concurrency: { type: 'string', default: '8' },
		plans: { type: 'boolean', default: false },
		out: { type: 'string' },
	},
})
const target = loadTarget()
const skip = Number(args.skip)
const pads = JSON.parse(readFileSync(args.state, 'utf8')).pads.slice(skip, skip + Number(args.pads))
const pending = () => Number(sqlValue("select count(*) from oc_ep_pad_bindings where state = 'pending_delete'"))

// The file's path from its id, through the file cache; WebDAV wants the path.
const paths = new Map(sqlValue(`select fileid, substr(path, 7) from oc_filecache where fileid in (${pads.map((pad) => pad.fileId).join(',')})`)
	.split('\n').filter(Boolean).map((line) => line.split('|')).map(([id, path]) => [Number(id), path]))

async function deleteAndSweep() {
	const before = pending()
	const deletes = await eachLimited(pads, Number(args.concurrency), async (pad) => {
		const res = await request(target, 'DELETE', `remote.php/dav/files/${encodeURIComponent(target.user)}/${paths.get(pad.fileId)}`, { headers: { 'X-NC-Skip-Trashbin': 'true' } })
		if (res.status !== 204) {
			throw new Error(`DELETE ${pad.fileId}: ${res.status}`)
		}
		return res.ms
	})
	const waiting = pending()

	const runs = []
	for (let left = waiting; left > 0 && runs.length < 50;) {
		// A second on each side, for the Docker VM's clock: nothing else
		// calls Etherpad meanwhile.
		const since = new Date(Date.now() - 1000)
		const res = await appApi(target, 'POST', 'admin/settle-pending')
		if (res.status !== 200) {
			throw new Error(`settle-pending: ${res.status} ${JSON.stringify(res.body).slice(0, 200)}`)
		}
		const calls = await etherpadCalls(since, new Date(Date.now() + 1000))
		runs.push({ ms: Math.round(res.ms), checked: res.body.checked, settled: res.body.settled, left: res.body.pending_delete_count, etherpadCalls: calls })
		if (res.body.checked === 0) {
			break
		}
		left = res.body.pending_delete_count
	}

	return { before, waiting, deletes, runs }
}
const { result: { before, waiting, deletes, runs }, plans } = args.plans ? await withPlans(deleteAndSweep) : { result: await deleteAndSweep() }

const report = {
	deletes: { files: pads.length, pendingBefore: before, pendingAfter: waiting, ...spread(deletes) },
	sweepRuns: runs,
	...(plans ? { plans } : {}),
}
console.log(JSON.stringify(report, null, '\t'))
if (args.out) {
	writeFileSync(args.out, JSON.stringify(report, null, '\t'))
}
