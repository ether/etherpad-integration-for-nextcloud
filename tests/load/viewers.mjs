/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// Plays open viewers of the pads seed-pads.mjs made: each viewer opens its
// pad once and then sends a timed sync every interval the server names,
// skipping a tick while its last sync is in flight, as the browser's sync
// loop does. Meanwhile someone types into some of the pads through
// Etherpad's API. It reports what the syncs answered and how long they
// took, the Etherpad calls the app made and the file versions written, for
// the requests that start after a warm-up of one interval.
//
//   node tests/load/viewers.mjs --skip 0 --pads 200 --viewers-per-pad 5 --duration 180
//   node tests/load/viewers.mjs ... --unpaced    # syncs without periodic=1, as before pacing

import { readFileSync, writeFileSync } from 'node:fs'
import { parseArgs } from 'node:util'
import { appApi, eachLimited, etherpad, etherpadCalls, loadTarget, repo, request, spread, sqlValue } from './lib.mjs'

const { values: args } = parseArgs({
	options: {
		state: { type: 'string', default: `${repo}tests/load/.work/pads.json` },
		skip: { type: 'string', default: '0' },
		pads: { type: 'string' },
		'viewers-per-pad': { type: 'string', default: '5' },
		typed: { type: 'string' },
		'typing-ms': { type: 'string', default: '1000' },
		duration: { type: 'string', default: '180' },
		unpaced: { type: 'boolean', default: false },
		concurrency: { type: 'string', default: '8' },
		out: { type: 'string' },
	},
})
const target = loadTarget()
const allPads = JSON.parse(readFileSync(args.state, 'utf8')).pads
// Each run on pads of its own: one that overloads the stack can leave locks
// on its files for an hour (filelocking.ttl), and the next run on them would
// measure those.
const skip = Number(args.skip)
const pads = allPads.slice(skip, args.pads ? skip + Number(args.pads) : allPads.length)
const perPad = Number(args['viewers-per-pad'])
const typed = pads.slice(0, args.typed ? Number(args.typed) : Math.ceil(pads.length / 5))
const durationMs = Number(args.duration) * 1000

// Every viewer opens its pad, as the Viewer does before its first sync.
const viewers = pads.flatMap((pad) => Array.from({ length: perPad }, () => ({ pad })))
const opens = await eachLimited(viewers, Number(args.concurrency), async (viewer) => {
	const res = await appApi(target, 'POST', 'pads/open-by-id', { fileId: String(viewer.pad.fileId) })
	if (res.status !== 200 || !res.body.sync_url) {
		throw new Error(`open ${viewer.pad.fileId}: ${res.status} ${JSON.stringify(res.body).slice(0, 200)}`)
	}
	viewer.syncUrl = res.body.sync_url + (args.unpaced ? '' : '?periodic=1')
	return { ms: res.ms, interval: res.body.sync_interval_seconds }
})
const intervalMs = opens[0].interval * 1000
const fileIds = pads.map((pad) => pad.fileId).join(',')
// Null where the database takes no more connections, as an overloaded
// stack's does: the report still comes out.
const versions = () => {
	try {
		return Number(sqlValue(`select count(*) from oc_files_versions where file_id in (${fileIds})`))
	} catch {
		return null
	}
}

const syncs = []
const errors = []
let skipped = 0
let stopping = false
const timers = []
const inFlight = new Set()
const start = Date.now()
const measureFrom = start + intervalMs
const measureUntil = measureFrom + durationMs
let versionsAtMeasure = null

for (const viewer of viewers) {
	const tick = () => {
		if (stopping) {
			return
		}
		if (viewer.busy) {
			skipped += Date.now() >= measureFrom ? 1 : 0
			return
		}
		viewer.busy = true
		const sent = Date.now()
		const call = request(target, 'POST', viewer.syncUrl)
			.then((res) => {
				syncs.push({ sent, ms: res.ms, http: res.status, status: res.body.status ?? `http ${res.status}`, fileId: viewer.pad.fileId })
				if (res.status !== 200 && errors.length < 5) {
					errors.push(`${res.status} ${JSON.stringify(res.body).slice(0, 200)}`)
				}
			})
			.catch((e) => {
				syncs.push({ sent, ms: Date.now() - sent, http: 0, status: 'failed', fileId: viewer.pad.fileId })
				if (errors.length < 5) {
					errors.push(String(e))
				}
			})
			.finally(() => {
				viewer.busy = false
				inFlight.delete(call)
			})
		inFlight.add(call)
	}
	// A random phase, as viewers open at random moments.
	timers.push(setTimeout(() => {
		tick()
		timers.push(setInterval(tick, intervalMs))
	}, Math.random() * intervalMs))
}
let typings = 0
for (const pad of typed) {
	timers.push(setInterval(() => {
		if (!stopping) {
			etherpad(target, 'appendText', { padID: pad.padId, text: 'x' }).then(() => typings++, (e) => errors.length < 5 && errors.push(String(e)))
		}
	}, Number(args['typing-ms'])))
}

await new Promise((resolve) => setTimeout(resolve, measureFrom - Date.now()))
versionsAtMeasure = versions()
await new Promise((resolve) => setTimeout(resolve, measureUntil - Date.now()))
stopping = true
timers.forEach((timer) => clearInterval(timer))
await Promise.allSettled([...inFlight])
const done = new Date()
const versionsAfter = versions()

// Only the syncs sent in the window count. The calls Etherpad logged from
// its start until the last sync came back are the app's, but for the typing
// and the container's health check; those of syncs still in flight as the
// window opened count too, so an overloaded run shows a few more per sync.
const measured = syncs.filter((s) => s.sent >= measureFrom && s.sent < measureUntil)
const byStatus = {}
for (const s of measured) {
	byStatus[s.status] ??= []
	byStatus[s.status].push(s.ms)
}
const calls = etherpadCalls(new Date(measureFrom), done)
delete calls.appendText
delete calls.checkToken
const callCount = Object.values(calls).reduce((a, b) => a + b, 0)
const intervals = durationMs / intervalMs
const looks = (byStatus.unchanged?.length ?? 0) + (byStatus.updated?.length ?? 0)
const writes = byStatus.updated?.length ?? 0
const typedIds = new Set(typed.map((pad) => pad.fileId))
const typedWrites = measured.filter((s) => s.status === 'updated' && typedIds.has(s.fileId)).length

const report = {
	setup: { pads: pads.length, viewers: viewers.length, typedPads: typed.length, intervalSeconds: intervalMs / 1000, durationSeconds: durationMs / 1000, paced: !args.unpaced },
	opens: spread(opens.map((o) => o.ms)),
	syncs: {
		sent: measured.length,
		perSecond: +(measured.length / (durationMs / 1000)).toFixed(1),
		ticksSkippedInFlight: skipped,
		byStatus: Object.fromEntries(Object.entries(byStatus).map(([status, ms]) => [status, spread(ms)])),
		all: spread(measured.map((s) => s.ms)),
	},
	perPadAndInterval: {
		looks: +(looks / pads.length / intervals).toFixed(2),
		writesOfTypedPads: +(typedWrites / typed.length / intervals).toFixed(2),
	},
	etherpadCalls: { total: callCount, perSync: +(callCount / Math.max(1, measured.length)).toFixed(2), perLook: +(callCount / Math.max(1, looks)).toFixed(2), byFunction: calls },
	fileVersions: { written: versionsAfter === null || versionsAtMeasure === null ? null : versionsAfter - versionsAtMeasure, writesSeen: writes },
	typings,
	errors,
}
console.log(JSON.stringify(report, null, '\t'))
if (args.out) {
	writeFileSync(args.out, JSON.stringify(report, null, '\t'))
}
