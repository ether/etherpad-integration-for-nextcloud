/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { listTrashbinEntries, purgeTrashbinEntry } from './fixtures/dav'
import { runId } from './fixtures/run-id'
import { classifyTrashEntry } from './fixtures/sweep-filter'

/**
 * Sweep the fixtures this run left in the trash.
 *
 * WebDAV `DELETE` on a live file moves it to the trash rather than
 * removing it, so every spec that cleans up after itself leaves an entry
 * behind — around a dozen per green run, on an account that is reused for
 * every run. They are invisible until the trash listing itself starts
 * failing, at which point every spec that reads the trash is stuck and
 * the cause is nowhere near the symptom.
 *
 * Only this run's own entries are purged; see sweep-filter.ts for why
 * nothing else is. Deleting here is permanent, so every purge is named in
 * the log — it is the one irreversible thing the suite does.
 *
 * Housekeeping must not decide whether the run passed. Failures are
 * reported and swallowed, and the requests underneath carry their own
 * timeout so a wedged instance cannot hang the runner either.
 */

/** Enough to overlap the round-trips without hammering the instance. */
const PURGE_CONCURRENCY = 4

export default async function globalTeardown(): Promise<void> {
	const id = runId()

	let entries: { entry: string, originalName: string }[]
	try {
		entries = await listTrashbinEntries()
	} catch (error) {
		console.warn(`[teardown] could not read the trash, skipping the sweep: ${String(error)}`)
		return
	}

	const ours: { entry: string, originalName: string }[] = []
	let foreign = 0
	let legacy = 0
	for (const candidate of entries) {
		switch (classifyTrashEntry(candidate.originalName, { runId: id })) {
			case 'ours': ours.push(candidate); break
			case 'foreign-run': foreign += 1; break
			case 'legacy': legacy += 1; break
			default: break
		}
	}

	const failed: string[] = []
	const queue = [...ours]
	const workers = Array.from({ length: Math.min(PURGE_CONCURRENCY, queue.length) }, async () => {
		for (let next = queue.shift(); next !== undefined; next = queue.shift()) {
			try {
				await purgeTrashbinEntry(next.entry)
				console.log(`[teardown] purged ${next.originalName}`)
			} catch (error) {
				failed.push(next.originalName)
				console.warn(`[teardown] could not purge "${next.originalName}": ${String(error)}`)
			}
		}
	})
	await Promise.all(workers)

	if (ours.length > 0) {
		console.log(`[teardown] purged ${ours.length - failed.length}/${ours.length} fixtures from run ${id}`)
	}
	if (foreign > 0) {
		console.log(`[teardown] left ${foreign} fixtures from other runs alone`)
	}
	if (legacy > 0) {
		console.log(`[teardown] ${legacy} trash entries predate run ids and cannot be attributed — purge them by hand if they are yours`)
	}
}
