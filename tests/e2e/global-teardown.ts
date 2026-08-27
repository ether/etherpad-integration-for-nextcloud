/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { listTrashbinEntries, purgeTrashbinEntry } from './fixtures/dav'
import { runId } from './fixtures/run-id'
import { classifyTrashEntry } from './fixtures/sweep-filter'

/**
 * Sweep the fixtures this suite left in the trash.
 *
 * WebDAV `DELETE` on a live file moves it to the trash rather than
 * removing it, so every spec that cleans up after itself leaves an entry
 * behind — around a dozen per green run, on an account that is reused for
 * every run. They are invisible until the trash listing itself starts
 * failing, at which point every spec that reads the trash is stuck and
 * the cause is nowhere near the symptom.
 *
 * What may be deleted is decided by `classifyTrashEntry`: entries
 * carrying this run's id, plus anything too old for a run to still be
 * using. Deleting here is permanent, so the rule is to purge what we can
 * positively account for and skip the rest.
 *
 * Housekeeping must not decide whether the run passed. Failures are
 * reported and swallowed — a broken trash listing is exactly the state
 * this exists to prevent, and throwing would mask whichever spec actually
 * failed.
 */
export default async function globalTeardown(): Promise<void> {
	const id = runId()
	const now = Date.now()

	let entries: { entry: string, originalName: string }[]
	try {
		entries = await listTrashbinEntries()
	} catch (error) {
		console.warn(`[teardown] could not read the trash, skipping the sweep: ${String(error)}`)
		return
	}

	const purgeable: { entry: string, originalName: string }[] = []
	let foreign = 0
	for (const candidate of entries) {
		const decision = classifyTrashEntry(candidate.originalName, { runId: id, now })
		if (decision === 'ours' || decision === 'stale') {
			purgeable.push(candidate)
		} else if (decision === 'foreign-run') {
			foreign += 1
		}
	}

	let purged = 0
	for (const candidate of purgeable) {
		try {
			await purgeTrashbinEntry(candidate.entry)
			purged += 1
		} catch (error) {
			console.warn(`[teardown] could not purge "${candidate.originalName}": ${String(error)}`)
		}
	}

	if (purgeable.length > 0) {
		console.log(`[teardown] purged ${purged}/${purgeable.length} e2e leftovers from the trash`)
	}
	if (foreign > 0) {
		console.log(`[teardown] left ${foreign} recent e2e entries alone — another run may still need them`)
	}
}
