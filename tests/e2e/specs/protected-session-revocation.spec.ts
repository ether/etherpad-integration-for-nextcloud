/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, type BrowserContext } from '@playwright/test'
import {
	closeViewer,
	expectEtherpadViewerMounted,
	gotoFiles,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createPadAtPath,
	deleteViaDav,
} from '../fixtures/dav'
import { authorIdForUid, groupIdOfPadUrl, liveSessionCount } from '../fixtures/etherpad'
import { E2E } from '../fixtures/env'
import { loginAs, logout } from '../fixtures/auth'

/**
 * An Etherpad session is a bearer token with its own lifetime, on its own
 * host. A Nextcloud logout does not reach it, so the app takes it away
 * itself — and whether it did is a question only the pad server can
 * answer. The unit tests pin which call is made; this asks Etherpad
 * whether the access is actually gone.
 *
 * The test ends at the pad URL rather than at a session count, because the
 * count is the mechanism and the URL is the claim: someone who knows the
 * address of a pad they may no longer open must not get in.
 *
 * `#permissionDenied` is Etherpad's own marker for a refused pad. It is
 * shown on `accessStatus: 'deny'` from the socket handshake, which is
 * where a group pad without a valid session lands.
 */
test.describe('protected pad session revocation', () => {
	// A form login, an Etherpad mount and the create flow, which is
	// several UI steps on its own.
	test.describe.configure({ timeout: 120_000 })

	const expectDeniedAt = async (context: BrowserContext, padUrl: string): Promise<void> => {
		const page = await context.newPage()
		try {
			await page.goto(padUrl)
			await expect(
				page.locator('#permissionDenied'),
				`Etherpad still let ${padUrl} be opened`,
			).toBeVisible({ timeout: 30_000 })
		} finally {
			await page.close()
		}
	}

	/**
	 * The same check in the other direction, run before the logout.
	 *
	 * `#permissionDenied` is in Etherpad's markup on every pad page and is
	 * only shown when the handshake is refused, so asserting that it is
	 * visible would also pass against a pad server that refuses everyone,
	 * or a stack where the pad never loaded at all. Asserting first that
	 * this same context reaches an editable pad rules both out — and it
	 * pins the premise the test rests on: the access was there to lose.
	 */
	const expectReachableAt = async (context: BrowserContext, padUrl: string): Promise<void> => {
		const page = await context.newPage()
		try {
			await page.goto(padUrl)
			await expect(
				page.locator('#editorcontainer .ace_outer, iframe[name="ace_outer"]').first(),
				`Etherpad did not open ${padUrl}`,
			).toBeVisible({ timeout: 30_000 })
			await expect(page.locator('#permissionDenied')).toBeHidden()
		} finally {
			await page.close()
		}
	}

	/**
	 * On its own login, not the stored one every other spec shares.
	 * Logging out ends the Nextcloud session behind whatever cookie the
	 * browser is carrying, and the setup project mints that cookie once for
	 * the whole run — so logging out of it would leave every spec after
	 * this one unauthenticated. Costs one form login; buys a suite that
	 * does not depend on file order.
	 */
	test('logging out of Nextcloud closes the pad the browser still has a cookie for', async ({ browser }) => {
		test.skip(E2E.etherpadApi === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured; Etherpad-side spec skipped.')

		const padName = uniquePadName('revoke-logout')
		// Explicitly empty, not merely "new": a context created inside a test
		// picks up the project's stored session, and the login form would
		// then never be reached — the request would land on the dashboard
		// already signed in.
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		try {
			await loginAs(page, E2E.user, E2E.password)
			await createPadAtPath(`/${padName}`, 'protected')

			// Through the UI, not the API: the session is minted when the pad
			// is opened, and the cookie has to end up in this browser for the
			// last assertion to mean anything.
			await gotoFiles(page)
			await openPadFromFileList(page, padName)
			await expectEtherpadViewerMounted(page)
			const padUrl = await readEtherpadUrlFromViewer(page)
			await closeViewer(page)

			const groupId = groupIdOfPadUrl(padUrl)
			const authorId = await authorIdForUid(E2E.user)
			expect(
				await liveSessionCount(groupId, authorId),
				'opening a protected pad should have issued a session',
			).toBeGreaterThan(0)

			await expectReachableAt(context, padUrl)

			await logout(page)

			await expect.poll(
				() => liveSessionCount(groupId, authorId),
				{ timeout: 30_000, message: 'logout should have revoked the sessions for this pad' },
			).toBe(0)

			// The cookie outlives the logout — a listener has no response to
			// clear it with, and the app says so. Asserting it is still here
			// is what makes the next line about revocation rather than about
			// a browser that happens to have forgotten something.
			//
			// It also settles what the unit tests cannot: they hand the
			// revoker its carried ids directly, so nothing there shows the
			// cookie actually reaching Nextcloud. This one is set for the
			// parent domain of both hosts, and the logout is a same-site
			// top-level GET — so a cookie still present here is a cookie the
			// logout request carried.
			const cookies = await context.cookies(padUrl)
			expect(
				cookies.some((cookie) => cookie.name === 'sessionID'),
				'the Etherpad cookie is expected to survive the logout',
			).toBe(true)

			await expectDeniedAt(context, padUrl)
		} finally {
			await context.close()
			await deleteViaDav(padName).catch(() => {})
		}
	})
})
