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

	test.beforeAll(async () => {
		if (!E2E.hasSecondaryAccount()) {
			return
		}
		// File setup happens here so the spec body stays focused on the
		// permission assertion.
	})

	test.afterAll(async () => {
		if (createdFileId !== null) {
			await deleteViaDav(padName)
		}
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
		const apiCtx = await playwrightRequest.newContext({
			baseURL: E2E.baseURL,
			extraHTTPHeaders: {
				Authorization: 'Basic ' + Buffer.from(`${userB}:${userBPassword}`).toString('base64'),
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			},
		})

		try {
			const res = await apiCtx.post(
				`/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id/${createdFileId}`,
			)
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
	})
})
