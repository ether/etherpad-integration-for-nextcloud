/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Decides what the trash sweep is allowed to delete permanently. Kept
 * free of imports so it can be unit-tested without a target instance.
 */

/**
 * The names the specs generate: `e2e-<label>-<timestamp>` with an
 * optional extension. `.pad` covers the pads, `.txt` the public-share
 * fixtures, and no extension at all the folders (`e2e-move-folder-…`,
 * `e2e-tmpl-…`).
 *
 * Deliberately narrow. The sweep deletes permanently, so anything it
 * cannot positively recognise as ours is left alone — an unknown
 * extension is somebody's file, not a fixture.
 */
const FIXTURE_NAME = /^e2e-[a-z0-9-]+-(\d{13})(?:\.(?:pad|txt))?$/

/** How long an unrecognised leftover has to sit before it counts as stale. */
export const STALE_AFTER_MS = 2 * 60 * 60 * 1000

export type SweepDecision = 'ours' | 'stale' | 'foreign-run' | 'not-ours'

/**
 * `ours` — created by this run, purge.
 * `stale` — older than any plausible run, so a leftover from one that was
 *   interrupted; purge.
 * `foreign-run` — matches the fixture shape but predates this run and is
 *   too recent to be stale. Another suite may be running against the same
 *   instance right now and about to restore it; leave it.
 * `not-ours` — does not match at all.
 */
export const classifyTrashEntry = (
	originalName: string,
	options: { runStartedAt: number, now: number, staleAfterMs?: number },
): SweepDecision => {
	const match = FIXTURE_NAME.exec(originalName)
	if (match === null) {
		return 'not-ours'
	}

	const createdAt = Number(match[1])
	if (createdAt >= options.runStartedAt) {
		return 'ours'
	}
	return options.now - createdAt > (options.staleAfterMs ?? STALE_AFTER_MS)
		? 'stale'
		: 'foreign-run'
}
