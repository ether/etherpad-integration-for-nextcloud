/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { E2E } from './env'

const composeFile = resolve(dirname(fileURLToPath(import.meta.url)), '../docker/compose.yml')

/** Doubled for the string and doubled again for LIKE: the class names hold backslashes. */
const REVOCATION_JOBS = 'OCA\\\\EtherpadNextcloud\\\\BackgroundJob\\\\Revoke%'

/**
 * Makes the waiting revocation jobs due now, for cron.php to run them: their
 * first pass waits a minute, and a test would spend it on nothing. The nightly
 * run leaves them to come due themselves (E2E.realJobWait), which is the one
 * thing this skips. For the Docker stack only, whose database is reachable and
 * throwaway.
 */
export const revocationJobsFallDue = (): void => {
	if (E2E.realJobWait) {
		return
	}
	execFileSync('docker', [
		'compose', '-f', composeFile, 'exec', '-T', 'db',
		'psql', '-U', 'nextcloud', '-d', 'nextcloud', '-qc',
		`UPDATE oc_jobs SET last_checked = 0 WHERE class LIKE '${REVOCATION_JOBS}'`,
	], { stdio: 'pipe' })
}
