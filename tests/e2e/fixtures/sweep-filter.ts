/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Decides what the trash sweep is allowed to delete permanently. Kept
 * free of imports so it can be unit-tested without a target instance.
 */

/** Marks the run id inside a fixture name, so a label cannot be mistaken for one. */
export const runToken = (runId: string): string => `r${runId}`

/**
 * `e2e-<label>-r<runid>-<timestamp>` with an optional extension: `.pad`
 * for pads, `.txt` for the public-share fixtures, none at all for the
 * folders (`e2e-move-folder-…`, `e2e-tmpl-…`).
 *
 * Deliberately narrow. The sweep deletes permanently, so anything it
 * cannot positively account for is left alone.
 */
const FIXTURE_NAME = /^e2e-[a-z0-9-]+-r([0-9a-f]{8})-(\d{13})(?:\.(?:pad|txt))?$/

/** The same shape from before run ids, kept so old leftovers still get cleaned up. */
const LEGACY_FIXTURE_NAME = /^e2e-[a-z0-9-]+-(\d{13})(?:\.(?:pad|txt))?$/

/** How long an entry we cannot attribute has to sit before it counts as abandoned. */
export const STALE_AFTER_MS = 2 * 60 * 60 * 1000

export type SweepDecision = 'ours' | 'stale' | 'foreign-run' | 'not-ours'

/**
 * `ours` — carries this run's id, purge.
 * `stale` — someone else's or from before run ids, and old enough that no
 *   run could still be using it; purge.
 * `foreign-run` — belongs to a different run that may still be going.
 *   Leave it: its suite may be about to restore that very entry.
 * `not-ours` — not a fixture name at all.
 *
 * Ownership is decided by the id, never by time. A timestamp cannot say
 * who created an entry: a run that starts later has later timestamps
 * throughout, so an earlier run would read all of them as its own and
 * delete the files that run is still working with.
 */
export const classifyTrashEntry = (
	originalName: string,
	options: { runId: string, now: number, staleAfterMs?: number },
): SweepDecision => {
	const staleAfterMs = options.staleAfterMs ?? STALE_AFTER_MS

	const match = FIXTURE_NAME.exec(originalName)
	if (match !== null) {
		if (match[1] === options.runId) {
			return 'ours'
		}
		return options.now - Number(match[2]) > staleAfterMs ? 'stale' : 'foreign-run'
	}

	const legacy = LEGACY_FIXTURE_NAME.exec(originalName)
	if (legacy === null) {
		return 'not-ours'
	}
	// No id to go on, so age is all there is.
	return options.now - Number(legacy[1]) > staleAfterMs ? 'stale' : 'foreign-run'
}
