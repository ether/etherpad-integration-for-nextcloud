/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import {
	createPublicPad,
	gotoFiles,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, propfindFileId } from '../fixtures/dav'
import { E2E } from '../fixtures/env'

/**
 * Cross-user permission boundary: a Nextcloud account must not be able
 * to open another account's `.pad` through our open-by-id API just by
 * knowing the fileid. The check sits on the API surface rather than in
 * the browser because the Files app would never even show the row to
 * the second user — the meaningful failure mode is an attacker hitting
 * the endpoint directly with a guessed id.
 *
 * Skips when E2E_USER2 / E2E_USER2_APP_PASSWORD are not configured so
 * the standard single-account test environment still passes.
 */
test.describe('pad ownership boundary (cross-user open-by-id)', () => {
	const padName = uniquePadName('ownership')
	let createdFileId: number | null = null

	test.afterAll(async () => {
		// Always attempt cleanup by name: the file is created before
		// createdFileId is assigned, so gating on that id could leak the
		// pad if the test threw in between. deleteViaDav no-ops on 404.
		await deleteViaDav(padName).catch(() => {})
	})

	test('user B cannot open user A\'s pad via the open-by-id endpoint', async ({ page }) => {
		test.skip(
			!E2E.hasSecondaryAccount(),
			'E2E_USER2 / E2E_USER2_APP_PASSWORD not configured; cross-user spec skipped.',
		)

		// User A path: create through the regular UI, then read the
		// fileid via WebDAV PROPFIND.
		await gotoFiles(page)
		await createPublicPad(page, padName)
		createdFileId = await propfindFileId(padName)
		expect(createdFileId).toBeGreaterThan(0)

		// User B path: a fresh API context authenticated as the
		// secondary account hits open-by-id with user A's fileid.
		const userB = E2E.secondaryUser!
		const userBPassword = E2E.secondaryAppPassword!
		// storageState is cleared explicitly. request.newContext() inherits
		// the project's `use` options inside a test, and this project sets
		// storageState to the primary account's saved session — so the
		// context arrived carrying admin's session cookie, Nextcloud
		// authenticated by that rather than by the Authorization header, and
		// "user B" was user A. The endpoint answered 200, correctly, for the
		// owner.
		const apiCtx = await playwrightRequest.newContext({
			baseURL: E2E.baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: 'Basic ' + Buffer.from(`${userB}:${userBPassword}`).toString('base64'),
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			},
		})

		// The file id goes in the body. There is no `/open-by-id/{fileId}`
		// route — this used to post one, so Nextcloud's router answered 404
		// before any controller ran and the assertion below was satisfied by
		// a typo rather than by the boundary it is named after.
		const openByIdUrl = '/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id'
		const form = { fileId: String(createdFileId) }

		try {
			const res = await apiCtx.post(openByIdUrl, { form })
			// Anything 2xx would mean user B got an open ticket for user A's
			// pad — that's the security regression we want to catch.
			expect(
				res.status(),
				`open-by-id should reject cross-user access, got HTTP ${res.status()}`,
			).toBeGreaterThanOrEqual(400)
			expect(res.status()).toBeLessThan(500)
		} finally {
			await apiCtx.dispose()
		}

		// And the same request as the owner succeeds. Without this the
		// rejection above proves nothing: a route that answers 4xx for
		// everyone would pass it just as well.
		const ownerCtx = await playwrightRequest.newContext({
			baseURL: E2E.baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: 'Basic ' + Buffer.from(`${E2E.user}:${E2E.appPassword}`).toString('base64'),
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			},
		})
		try {
			const res = await ownerCtx.post(openByIdUrl, { form })
			expect(res.status(), `the owner should be able to open their own pad by id, got HTTP ${res.status()}`).toBe(200)
		} finally {
			await ownerCtx.dispose()
		}
	})
})
