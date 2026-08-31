/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { basicAuthHeader, createPadAtPath, deleteViaDav } from '../fixtures/dav'
import { uniquePadName } from '../fixtures/nextcloud'

/**
 * Holds the cookie that actually goes out against the release `/health`
 * reports, so one spec is right on both halves of the matrix.
 *
 * The other `protected-*` specs cannot see this: they work at request
 * level, where nothing needs to read the cookie from JavaScript, and pass
 * either way. Which is why this one exists.
 *
 * What a wrong flag costs is a property of Etherpad, not of this app, and
 * is measured by hand — on an Etherpad 2 stack the pad loads and never
 * becomes usable:
 *
 *     occ config:app:set etherpad_nextcloud \
 *       etherpad_http_only_session_cookie --value=yes
 *     tests/e2e/docker/run-suite.sh pad-author-display-name
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
			// EtherpadReleasePolicy's HTTP_ONLY_SINCE_MAJOR, restated rather
			// than imported: a test that derives its expectation from the
			// thing under test asserts nothing.
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

			expect(setCookie).toContain('Secure')
			// Lax, the default: the value comes from
			// etherpad_session_cookie_samesite and from nothing else, and
			// this stack does not set it. Nextcloud and Etherpad share a
			// registrable domain, so the pad iframe is a same-site
			// subresource and a foreign page framing a pad URL gets nothing.
			expect(setCookie).toContain('SameSite=Lax')

			if (readsItServerSide) {
				expect(setCookie, `Etherpad ${release} reads the session server-side`).toContain('HttpOnly')
			} else {
				expect(setCookie, `Etherpad ${release} reads the session in the browser`).not.toContain('HttpOnly')
			}

			// An absent flag is the default, so on an Etherpad 2 target the
			// branch above passes just as well when detection is broken end
			// to end. This spec asks /health from the runner's network; the
			// app asks from inside the container. So make it say what it
			// *stored* — only possible if that whole path ran.
			const connectionTest = await api.post(
				`${E2E.baseURL}/index.php/apps/etherpad_nextcloud/api/v1/admin/health`,
				{
					headers: {
						Authorization: basicAuthHeader(),
						'OCS-APIRequest': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					// The connection test validates the form as submitted, and
					// the base URL is required there. Same server either way.
					data: { etherpad_host: etherpadUrl! },
				},
			)
			expect(connectionTest.status(), await connectionTest.text()).toBe(200)
			const body = (await connectionTest.json()) as {
				session_cookie_release?: string,
				checks?: Array<{ id: string }>,
			}
			expect(
				body.checks?.some((c) => c.id === 'session_cookie'),
				'the connection test should report on the session cookie',
			).toBe(true)
			// The dedicated field, not the prose: one of the line's passing
			// shapes fills the release from the connection test's own probe,
			// so matching the sentence would pass with nothing cached.
			expect(
				body.session_cookie_release,
				'the app should have cached a release of its own while opening the pad',
			).toBe(release)
		} finally {
			await api.dispose()
		}
	})
})
