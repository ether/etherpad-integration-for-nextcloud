/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { isLegacyFixtureName, runIdOf } from './fixture-name'

/**
 * Decides what the trash sweep may delete permanently.
 *
 * Only entries carrying this run's id. Not "probably ours", not "old
 * enough that nobody can still want it" — the sweep deletes permanently
 * on an account a person also uses, so the rule is positive attribution
 * or nothing.
 *
 * An earlier version purged unattributable entries past an age
 * threshold. That is a time-based ownership judgement wearing a
 * different hat: it would delete a maintainer's own
 * `e2e-notes-<timestamp>.txt`, and between two machines with drifting
 * clocks it would delete entries a concurrent run was still using.
 */
export type SweepDecision = 'ours' | 'foreign-run' | 'legacy' | 'not-ours'

/**
 * `ours` — carries this run's id, purge.
 * `foreign-run` — a fixture from a different run. Leave it: that suite may
 *   be about to restore this very entry.
 * `legacy` — our name shape from before run ids. Cannot be attributed, so
 *   it is only reported; a person decides.
 * `not-ours` — not a fixture name at all.
 */
export const classifyTrashEntry = (originalName: string, options: { runId: string }): SweepDecision => {
	const owner = runIdOf(originalName)
	if (owner !== null) {
		return owner === options.runId ? 'ours' : 'foreign-run'
	}
	return isLegacyFixtureName(originalName) ? 'legacy' : 'not-ours'
}
