/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// Makes protected pads through the app, as a user's "New pad" does - file,
// row and Etherpad pad each - in folders of the target's user, 500 to a
// folder unless told otherwise, and writes their file and pad ids to a
// state file the other scripts read.
//
//   node tests/load/seed-pads.mjs --pads 1000

import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { parseArgs } from 'node:util'
import { appApi, eachLimited, loadTarget, repo, request, spread } from './lib.mjs'

const { values: args } = parseArgs({
	options: {
		pads: { type: 'string', default: '1000' },
		folder: { type: 'string', default: 'load' },
		'per-folder': { type: 'string', default: '500' },
		concurrency: { type: 'string', default: '8' },
		state: { type: 'string', default: `${repo}tests/load/.work/pads.json` },
	},
})
const count = Number(args.pads)
const target = loadTarget()

const perFolder = Number(args['per-folder'])
const run = Date.now().toString(36)
const folders = [args.folder, ...Array.from({ length: Math.ceil(count / perFolder) }, (_, n) => `${args.folder}/${run}-${n}`)]
for (const folder of folders) {
	const res = await request(target, 'MKCOL', `remote.php/dav/files/${encodeURIComponent(target.user)}/${folder}`)
	// 405: the folder is there already, from an earlier run.
	if (res.status !== 201 && res.status !== 405) {
		throw new Error(`MKCOL /${folder}: ${res.status}`)
	}
}

const started = Date.now()
const created = await eachLimited(Array.from({ length: count }, (_, i) => i), Number(args.concurrency), async (i) => {
	const name = `/${folders[1 + Math.floor(i / perFolder)]}/pad-${String(i).padStart(5, '0')}.pad`
	const res = await appApi(target, 'POST', 'pads', { file: name, accessMode: 'protected' })
	if (res.status !== 200 || !res.body.file_id) {
		throw new Error(`create ${name}: ${res.status} ${JSON.stringify(res.body).slice(0, 200)}`)
	}
	return { fileId: res.body.file_id, padId: res.body.pad_id, ms: res.ms }
})
const seconds = (Date.now() - started) / 1000

mkdirSync(dirname(args.state), { recursive: true })
writeFileSync(args.state, JSON.stringify({ created: new Date().toISOString(), pads: created.map(({ fileId, padId }) => ({ fileId, padId })) }, null, '\t'))
console.log(JSON.stringify({ pads: count, seconds: +seconds.toFixed(1), perSecond: +(count / seconds).toFixed(1), create: spread(created.map((c) => c.ms)), state: args.state }, null, '\t'))
