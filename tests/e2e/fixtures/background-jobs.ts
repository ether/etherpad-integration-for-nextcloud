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
 * Removes due-time waits from queued revocation jobs, including retries and
 * follow-up passes. cron.php still selects and runs the jobs. Nightly runs
 * retain scheduled delays (E2E.realJobWait). Only for the throwaway Docker
 * stack.
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
