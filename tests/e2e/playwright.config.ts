/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { config as loadEnv } from 'dotenv'

const here = dirname(fileURLToPath(import.meta.url))

// Load tests/e2e/.env.e2e if present (gitignored). All real values —
// base URL, user, passwords — live there, never in the repo.
loadEnv({ path: resolve(here, '.env.e2e') })

// No localhost fallback on purpose: specs use the absolute URLs from
// env.ts (which throws a clear "Missing env var" if E2E_BASE_URL is
// unset). Leaving this undefined means any future spec that relied on a
// relative path would fail fast rather than silently hit localhost.
const baseURL = process.env.E2E_BASE_URL || undefined

export default defineConfig({
	testDir: here,
	// Purges the pads the specs leave in the trash — see the file header
	// for why DELETE alone is not enough.
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
		ignoreHTTPSErrors: false,
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
