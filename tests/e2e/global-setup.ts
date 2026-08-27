/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { randomBytes } from 'node:crypto'

/**
 * Stamp this run with an id before any spec builds a file name. The trash
 * sweep in global-teardown uses it to tell its own leftovers from those of
 * a suite running against the same instance at the same time — time alone
 * cannot tell them apart, because a run that starts later has later
 * timestamps throughout.
 */
export default async function globalSetup(): Promise<void> {
	process.env.E2E_RUN_ID ??= randomBytes(4).toString('hex')
}
