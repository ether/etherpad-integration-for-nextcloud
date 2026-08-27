/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { randomBytes } from 'node:crypto'

let fallback: string | null = null

/**
 * The id stamped into every fixture name so the trash sweep can tell this
 * run's leftovers from those of a suite running against the same instance
 * at the same time. globalSetup puts it in the environment before the
 * workers start, so runner and workers agree on it.
 *
 * The fallback only matters if a spec is driven without globalSetup. It
 * differs per process, so the sweep then recognises nothing as its own
 * and deletes nothing — the safe direction.
 */
export const runId = (): string => {
	const fromEnv = process.env.E2E_RUN_ID?.trim()
	if (fromEnv !== undefined && fromEnv !== '') {
		return fromEnv
	}
	fallback ??= randomBytes(4).toString('hex')
	return fallback
}
