/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { randomBytes } from 'node:crypto'
import { normaliseRunId } from './fixture-name'

let fallback: string | null = null

/**
 * The id stamped into every fixture name so the trash sweep can tell this
 * run's leftovers from those of a suite running against the same instance
 * at the same time. globalSetup puts it in the environment before the
 * workers start, so runner and workers agree on it.
 *
 * An id from outside is folded into the expected shape rather than used
 * verbatim: `E2E_RUN_ID=$GITHUB_RUN_ID` would otherwise build names the
 * sweep cannot recognise, and it would fail by silently purging nothing.
 * An empty value counts as unset — `??=` does not overwrite `''`, so a
 * blank variable in an env file would leave runner and workers minting
 * different ids.
 */
export const runId = (): string => {
	const fromEnv = process.env.E2E_RUN_ID?.trim()
	if (fromEnv !== undefined && fromEnv !== '') {
		return normaliseRunId(fromEnv)
	}
	fallback ??= randomBytes(4).toString('hex')
	return fallback
}
