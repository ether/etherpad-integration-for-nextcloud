/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { config as loadEnv } from 'dotenv'
import { existsSync } from 'node:fs'

const here = dirname(fileURLToPath(import.meta.url))

// Load tests/e2e/.env.e2e if present (gitignored). All real values —
// base URL, user, passwords — live there, never in the repo.
//
// E2E_ENV_FILE points the suite at a different target without touching
// that file: tests/e2e/docker/up.sh writes .env.e2e.docker for the
// throwaway container stack, and CI sets the variable to it.
// An explicit E2E_ENV_FILE overrides variables already exported in the
// shell; the default .env.e2e keeps dotenv's usual "the environment
// wins" behaviour. Without that, someone with E2E_BASE_URL exported for
// the long-lived instance would run the container target's env file and
// still drive their real instance — creating and deleting files there.
const resolveEnvFile = (value: string): string => {
	const fromCwd = resolve(process.cwd(), value)
	return existsSync(fromCwd) ? fromCwd : resolve(here, value)
}

const envFile = process.env.E2E_ENV_FILE?.trim()
const explicitTarget = envFile !== undefined && envFile !== ''
const loaded = loadEnv({
	// Resolved against the working directory first, then against this
	// file's directory, so the documented relative path works from the
	// repo root and from tests/e2e alike — every other path in this config
	// is relative to `here`.
	path: explicitTarget ? resolveEnvFile(envFile as string) : resolve(here, '.env.e2e'),
	override: explicitTarget,
})
// dotenv reports a missing or unreadable file in its return value rather
// than throwing. Carrying on would mean running with whatever E2E_* the
// shell happens to hold — which is how a container run ends up driving a
// real instance. Someone who named a target file meant it.
if (explicitTarget && loaded.error) {
	throw new Error(`E2E_ENV_FILE "${envFile}" could not be loaded: ${loaded.error.message}`)
}
// An empty or truncated file loads without an error and would leave every
// variable at whatever the shell held — the failure this check exists to
// prevent. A target file that does not name the instance is not a target.
if (explicitTarget && !loaded.parsed?.E2E_BASE_URL) {
	throw new Error(`E2E_ENV_FILE "${envFile}" does not define E2E_BASE_URL; refusing to fall back to the environment.`)
}

// No localhost fallback on purpose: specs use the absolute URLs from
// env.ts (which throws a clear "Missing env var" if E2E_BASE_URL is
// unset). Leaving this undefined means any future spec that relied on a
// relative path would fail fast rather than silently hit localhost.
const baseURL = process.env.E2E_BASE_URL || undefined

export default defineConfig({
	testDir: here,
	// globalSetup only stamps this run's id; the teardown needs it to tell
	// its own trash leftovers from a concurrent run's.
	globalSetup: resolve(here, 'global-setup.ts'),
	// Purges the fixtures the specs leave in the trash — see the file
	// header for why DELETE alone is not enough.
	globalTeardown: resolve(here, 'global-teardown.ts'),
	// One worker by default: tests run against a shared real instance,
	// so serial execution avoids create/cleanup races. Bump locally with
	// --workers if your target can take it.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	// Single retry locally too — long full-suite runs against a real NC
	// occasionally hit transient ERR_NETWORK_CHANGED or WebDAV 423 lock
	// contention; one retry hides those without papering over real bugs.
	retries: process.env.CI ? 2 : 1,
	// A test that only passes on retry still shows up as `flaky` and leaves
	// the run green, which is how an OCS race on Nextcloud 31 sat unnoticed
	// in the first CI run. Retries stay — the second attempt is useful
	// evidence — but in CI a flaky result fails the run, so the race gets
	// fixed instead of tolerated. Local runs stay forgiving.
	failOnFlakyTests: !!process.env.CI,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'never' }]],
	outputDir: resolve(here, '../../test-results'),

	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		// NC's session cookies need a real browser context; storageState is
		// produced by the "setup" project below.
		//
		// Certificate errors are fatal against a real instance. The Docker
		// stack signs itself with a CA it mints for itself, which the browser has
		// no reason to trust and which proves nothing about the app, so
		// up.sh opts that target out explicitly. Node's own fetch stays
		// strict either way and gets the CA via NODE_EXTRA_CA_CERTS.
		ignoreHTTPSErrors: process.env.E2E_IGNORE_HTTPS_ERRORS === '1',
	},

	projects: [
		{
			name: 'setup',
			testMatch: /auth\.setup\.ts$/,
		},
		{
			name: 'chromium',
			testMatch: /specs\/.*\.spec\.ts$/,
			dependencies: ['setup'],
			use: {
				...devices['Desktop Chrome'],
				storageState: resolve(here, '.auth/state.json'),
			},
		},
	],
})
