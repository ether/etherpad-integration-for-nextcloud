/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Reads a required environment variable or throws a clear error telling
 * the maintainer to fill in tests/e2e/.env.e2e. Keeps credentials out of
 * the repo — see .env.e2e.example for the expected keys.
 */
export const requireEnv = (name: string): string => {
	const value = process.env[name]
	if (!value || value.trim() === '') {
		throw new Error(
			`Missing env var ${name}. Copy tests/e2e/.env.e2e.example to `
			+ `tests/e2e/.env.e2e and fill in your test instance's values.`,
		)
	}
	return value.trim()
}

export const E2E = {
	get baseURL(): string {
		return requireEnv('E2E_BASE_URL').replace(/\/+$/, '')
	},
	get loginURL(): string {
		const value = process.env.E2E_LOGIN_URL?.trim() || '/login'
		if (/^https?:\/\//i.test(value)) {
			return value
		}
		return `${this.baseURL}/${value.replace(/^\/+/, '')}`
	},
	get user(): string {
		return requireEnv('E2E_USER')
	},
	get password(): string {
		return requireEnv('E2E_PASS')
	},
	/**
	 * App password used for non-browser WebDAV/API setup and teardown
	 * (mirrors the NC_APP_PASSWORD pattern in tests/integration/*.sh).
	 *
	 * Browser specs still use E2E_PASS for the real login form because
	 * Nextcloud app passwords are primarily BasicAuth credentials for
	 * clients, WebDAV and OCS endpoints, not a stable web-login contract.
	 */
	get appPassword(): string {
		return requireEnv('E2E_APP_PASSWORD')
	},

	/**
	 * Optional second account used by cross-user permission specs. When
	 * either of these is unset the relevant specs skip themselves with a
	 * clear message rather than throwing.
	 */
	get secondaryUser(): string | null {
		const value = process.env.E2E_USER2?.trim()
		return value && value !== '' ? value : null
	},
	get secondaryAppPassword(): string | null {
		const value = process.env.E2E_USER2_APP_PASSWORD?.trim()
		return value && value !== '' ? value : null
	},
	hasSecondaryAccount(): boolean {
		return this.secondaryUser !== null && this.secondaryAppPassword !== null
	},
}
