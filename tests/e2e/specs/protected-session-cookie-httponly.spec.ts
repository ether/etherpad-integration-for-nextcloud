/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { basicAuthHeader, createPadAtPath, deleteViaDav } from '../fixtures/dav'
import { uniquePadName } from '../fixtures/nextcloud'

/**
 * Up to Etherpad 2.7.3 the pad app reads `sessionID` itself, in the
 * browser, so the cookie has to stay script-readable — `HttpOnly` there is
 * not a hardening but a lockout from every protected pad. From 3.0.0 the
 * server takes the session id out of the socket.io handshake and nothing
 * on the page needs to see it.
 *
 * The app asks `/health` which one it is talking to. This spec asks the
 * same question and holds the answer against the cookie that actually
 * goes out, so one spec is right on both halves of the matrix.
 *
 * What the flag being wrong actually costs is a property of Etherpad
 * rather than of this app, and it is measured by hand. The recipe, so it
 * is repeatable rather than remembered:
 *
 *     occ config:app:set etherpad_nextcloud \
 *       etherpad_http_only_session_cookie --value=yes
 *     tests/e2e/docker/run-suite.sh pad-author-display-name
 *
 * On an Etherpad 2 stack that fails: the pad loads and never becomes
 * usable, because its pad app reads the cookie with `Cookies.get`. On
 * 3.0.0 and 3.3.3 it passes. `EP_VERSION` takes a full tag, so
 * `EP_VERSION=3.0.0 tests/e2e/docker/up.sh` reproduces the boundary
 * itself.
 *
 * The other `protected-*` specs cannot see any of this — they work at
 * request level, where nothing ever needs to read the cookie from
 * JavaScript, and they pass either way. Which is why this one exists.
 */
test.describe('the session cookie and the Etherpad that reads it', () => {
	const padName = uniquePadName('httponly')

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('is kept from scripts exactly where Etherpad reads it server-side', async () => {
		const etherpadUrl = E2E.etherpadUrl
		test.skip(etherpadUrl === null, 'E2E_ETHERPAD_URL not configured; Etherpad-side spec skipped.')

		// `storageState` is inherited from the project inside a test, and a
		// browser session here would authenticate as somebody else.
		const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const health = await api.get(`${etherpadUrl!}/health`)
			expect(health.status()).toBe(200)
			const release = String(((await health.json()) as { releaseId?: string }).releaseId ?? '')
			expect(release, 'Etherpad /health should report a releaseId').toMatch(/^\d+\.\d+\.\d+/)
			// The same rule the app applies, and the reason it is the major
			// rather than the full version: this has to be expressible here
			// too, and PHP sorts `3.0.0-beta.1` below `3.0.0` while any
			// reading of it here would call it a 3.
			const readsItServerSide = Number(release.split('.')[0]) >= 3

			await createPadAtPath(`/${padName}`, 'protected')

			const opened = await api.post(
				`${E2E.baseURL}/index.php/apps/etherpad_nextcloud/api/v1/pads/open`,
				{
					headers: { Authorization: basicAuthHeader(), 'OCS-APIRequest': 'true', Accept: 'application/json' },
					form: { file: `/${padName}` },
				},
			)
			expect(opened.status(), await opened.text()).toBe(200)

			const setCookie = opened.headersArray()
				.filter((h) => h.name.toLowerCase() === 'set-cookie')
				.map((h) => h.value)
				.find((value) => value.startsWith('sessionID='))
			expect(setCookie, 'a protected open should set the Etherpad session cookie').toBeDefined()

			// Secure and SameSite=None hold either way: the cookie has to reach
			// a pad server on another host.
			expect(setCookie).toContain('Secure')
			expect(setCookie).toContain('SameSite=None')

			if (readsItServerSide) {
				expect(setCookie, `Etherpad ${release} reads the session server-side`).toContain('HttpOnly')
			} else {
				expect(setCookie, `Etherpad ${release} reads the session in the browser`).not.toContain('HttpOnly')
			}
		} finally {
			await api.dispose()
		}
	})
})
