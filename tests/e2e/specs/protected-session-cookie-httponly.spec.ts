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
 * What the flag being wrong actually costs is measured elsewhere:
 * `pad-author-display-name` drives Etherpad's own toolbar two frames
 * deep, and with `HttpOnly` forced on 2.7.3 the pad app never becomes
 * usable. The other `protected-*` specs cannot see that — they work at
 * request level, where nothing ever needs to read the cookie from
 * JavaScript, and they pass either way.
 */
test.describe('the session cookie and the Etherpad that reads it', () => {
	const padName = uniquePadName('httponly')

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('is kept from scripts exactly where Etherpad reads it server-side', async () => {
		const etherpad = E2E.etherpadApi
		test.skip(etherpad === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured; Etherpad-side spec skipped.')

		// `storageState` is inherited from the project inside a test, and a
		// browser session here would authenticate as somebody else.
		const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })

		const health = await api.get(`${etherpad!.url}/health`)
		expect(health.status()).toBe(200)
		const release = String(((await health.json()) as { releaseId?: string }).releaseId ?? '')
		expect(release, 'Etherpad /health should report a releaseId').toMatch(/^\d+\.\d+\.\d+/)
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

		// Secure and SameSite=None hold either way: the cookie has to reach a
		// pad server on another host.
		expect(setCookie).toContain('Secure')
		expect(setCookie).toContain('SameSite=None')

		if (readsItServerSide) {
			expect(setCookie, `Etherpad ${release} reads the session server-side`).toContain('HttpOnly')
		} else {
			expect(setCookie, `Etherpad ${release} reads the session in the browser`).not.toContain('HttpOnly')
		}

		await api.dispose()
	})
})
