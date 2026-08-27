/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { randomBytes } from 'node:crypto'
import { normaliseRunId } from './fixtures/fixture-name'

/**
 * Stamp this run with an id before any spec builds a file name, so the
 * trash sweep in global-teardown can tell its own leftovers from those of
 * a suite running against the same instance at the same time.
 *
 * A blank E2E_RUN_ID counts as unset. `??=` would keep an empty string,
 * and runner and workers would then each mint their own id — the sweep
 * would recognise nothing as its own and quietly purge nothing.
 */
export default async function globalSetup(): Promise<void> {
	const supplied = process.env.E2E_RUN_ID?.trim()
	process.env.E2E_RUN_ID = supplied !== undefined && supplied !== ''
		? normaliseRunId(supplied)
		: randomBytes(4).toString('hex')
}
