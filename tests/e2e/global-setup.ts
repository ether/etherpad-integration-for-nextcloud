/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Records when this run started so the trash sweep in global-teardown can
 * tell its own leftovers from those of a suite running against the same
 * instance at the same time. Both hooks run in the runner process, so an
 * env var carries it across.
 */
export default async function globalSetup(): Promise<void> {
	process.env.E2E_RUN_STARTED_AT = String(Date.now())
}
