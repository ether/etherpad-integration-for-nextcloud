/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { listTrashbinEntries, purgeTrashbinEntry } from './fixtures/dav'

/**
 * Sweep the pads this suite left in the trash.
 *
 * WebDAV `DELETE` on a live file moves it to the trash rather than
 * removing it, so every spec that cleans up after itself with
 * `deleteViaDav` leaves an entry behind — a dozen or so per green run,
 * on an account that is reused for every run. They are invisible until
 * the trash listing itself starts failing, at which point every spec
 * that reads the trash is wedged and the cause is nowhere near the
 * symptom.
 *
 * The sweep is deliberately narrow: only entries whose original name
 * matches the `e2e-<label>-<timestamp>.<ext>` shape the specs generate
 * are purged — `.pad` from `uniquePadName`, plus the `.txt` fixtures the
 * public-share spec makes — so anything a human put in that account's
 * trash survives.
 * It also purges leftovers from earlier interrupted runs, which is what
 * makes it self-healing.
 *
 * Housekeeping must not decide whether the run passed: failures here are
 * reported loudly and swallowed. A broken trash listing is exactly the
 * state this exists to prevent, and it would otherwise mask whichever
 * spec actually failed.
 */
const E2E_FIXTURE_NAME = /^e2e-.+-\d{10,}\.[a-z0-9]+$/i

export default async function globalTeardown(): Promise<void> {
	let entries: { entry: string, originalName: string }[]
	try {
		entries = await listTrashbinEntries()
	} catch (error) {
		console.warn(`[teardown] could not read the trash, skipping the sweep: ${String(error)}`)
		return
	}

	const ours = entries.filter((candidate) => E2E_FIXTURE_NAME.test(candidate.originalName))
	let purged = 0
	for (const candidate of ours) {
		try {
			await purgeTrashbinEntry(candidate.entry)
			purged += 1
		} catch (error) {
			console.warn(`[teardown] could not purge "${candidate.originalName}": ${String(error)}`)
		}
	}

	if (ours.length > 0) {
		console.log(`[teardown] purged ${purged}/${ours.length} e2e leftovers from the trash`)
	}
}
