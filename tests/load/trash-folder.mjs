/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// Puts a folder of protected pads in the trash and restores it, one WebDAV
// request each, after every pad was opened so it has Etherpad sessions to
// lose. It reports how long each request took, the Etherpad calls the app
// made in it, and the session revocation jobs it queued. With --plans it
// does both once more while Postgres explains the statements on the app's
// table, and adds their plans. Make the folder first, as its own state
// file:
//
//   node tests/load/seed-pads.mjs --pads 100 --per-folder 100 --folder trash-test --state tests/load/.work/trash.json
//   node tests/load/trash-folder.mjs --state tests/load/.work/trash.json

import { readFileSync, writeFileSync } from 'node:fs'
import { parseArgs } from 'node:util'
import { appApi, eachLimited, etherpadCalls, loadTarget, repo, request, spread, sqlValue, withPlans } from './lib.mjs'

const { values: args } = parseArgs({
	options: {
		state: { type: 'string', default: `${repo}tests/load/.work/trash.json` },
		'opens-per-pad': { type: 'string', default: '1' },
		concurrency: { type: 'string', default: '8' },
		plans: { type: 'boolean', default: false },
		out: { type: 'string' },
	},
})
const target = loadTarget()
const pads = JSON.parse(readFileSync(args.state, 'utf8')).pads
const dav = `remote.php/dav/files/${encodeURIComponent(target.user)}`
const revocations = () => Number(sqlValue("select count(*) from oc_jobs where class like '%RevokeGroupSessionsJob'"))

// The folder the pads are in: one, as seed-pads.mjs makes it with
// --per-folder at least --pads.
const folders = sqlValue(`select distinct substr(p.path, 7) from oc_filecache f join oc_filecache p on p.fileid = f.parent where f.fileid in (${pads.map((pad) => pad.fileId).join(',')})`).split('\n').filter(Boolean)
if (folders.length !== 1) {
	throw new Error(`the pads are in ${folders.length} folders, not one`)
}
const folder = folders[0]

const opens = await eachLimited(pads.flatMap((pad) => Array(Number(args['opens-per-pad'])).fill(pad)), Number(args.concurrency), async (pad) => {
	const res = await appApi(target, 'POST', 'pads/open-by-id', { fileId: String(pad.fileId) })
	if (res.status !== 200) {
		throw new Error(`open ${pad.fileId}: ${res.status}`)
	}
	return res.ms
})

/** One request, with the Etherpad calls the app made while it ran. */
async function measured(method, path, headers) {
	const since = new Date()
	const res = await request(target, method, path, { headers })
	const calls = await etherpadCalls(since, new Date(Date.now() + 1000))
	return { status: res.status, ms: Math.round(res.ms), etherpadCalls: calls }
}

const name = folder.split('/').pop()
/** The folder into the trash and back. */
async function trashAndRestore() {
	const jobsBefore = revocations()
	const trash = await measured('DELETE', `${dav}/${folder}`)
	const jobsQueued = revocations() - jobsBefore
	// The trash names the folder after its name and when it was deleted.
	const listing = await request(target, 'PROPFIND', `remote.php/dav/trashbin/${encodeURIComponent(target.user)}/trash`, { headers: { Depth: '1' } })
	const item = [...(listing.body.text ?? '').matchAll(/<d:href>[^<]*\/trash\/([^<]+?)\/?<\/d:href>/g)].map((m) => decodeURIComponent(m[1])).find((entry) => entry.startsWith(`${name}.d`))
	if (!item) {
		throw new Error(`no ${name} in the trash`)
	}
	const restore = await measured('MOVE', `remote.php/dav/trashbin/${encodeURIComponent(target.user)}/trash/${encodeURIComponent(item)}`, {
		Destination: `${target.base}/remote.php/dav/trashbin/${encodeURIComponent(target.user)}/restore/${encodeURIComponent(name)}`,
	})
	return { trash: { ...trash, revocationJobsQueued: jobsQueued }, restore }
}

const report = {
	folder: { pads: pads.length, opens: spread(opens) },
	...await trashAndRestore(),
	// Once more, explained: the sessions are gone by now, so it asks Etherpad less.
	...(args.plans ? { plans: (await withPlans(trashAndRestore)).plans } : {}),
}
console.log(JSON.stringify(report, null, '\t'))
if (args.out) {
	writeFileSync(args.out, JSON.stringify(report, null, '\t'))
}
