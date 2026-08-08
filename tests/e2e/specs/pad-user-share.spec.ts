/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	closeViewer,
	createPublicPad,
	expectEtherpadViewerMounted,
	expectFileInList,
	gotoFiles,
	gotoSharedWithMe,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createUserReadShare,
	deleteShareById,
	deleteViaDav,
	propfindFileId,
} from '../fixtures/dav'
import { E2E } from '../fixtures/env'
import { SECONDARY_STATE_FILE } from '../fixtures/auth'

/**
 * User-to-user share lifecycle for a `.pad`:
 *   1. user A creates a pad through the UI
 *   2. user A grants user B read-only via the OCS share API
 *   3. user B opens the file from "Shared with me" → viewer mounts
 *   4. user A revokes the share
 *   5. user B's NC-side access is gone: the file disappears from
 *      "Shared with me", and a direct `?fileid=…` navigation does not
 *      mount the Etherpad iframe.
 *
 * Out of scope (deliberately): the Etherpad-side session cookie may
 * still let user B keep reading an already-open iframe for a short
 * window after revoke. NC's share API is the authoritative boundary;
 * this spec asserts the NC boundary, not the cookie lifecycle.
 *
 * Skips cleanly when E2E_USER2_PASS is not configured.
 */
test.describe('user-to-user pad share', () => {
	// Drives two browser contexts plus the full pad-create flow; user B's
	// session is pre-built by the setup project (storageState) so the test
	// itself does not pay for a form login. Still give some headroom for
	// the two real Etherpad viewer mounts — and for the extra step pad
	// creation gained when the type moved into Nextcloud's template picker.
	test.describe.configure({ timeout: 150_000 })

	const padName = uniquePadName('user-share')
	let shareId = ''

	test.afterAll(async () => {
		// Both calls are safe if the test ran to completion (share already
		// revoked, file already gone) — they only throw on unexpected non-
		// 404 statuses.
		if (shareId !== '') {
			await deleteShareById(shareId).catch(() => {})
		}
		await deleteViaDav(padName).catch(() => {})
	})

	test('grants user B access on share, removes access on revoke', async ({ page, browser }) => {
		test.skip(
			!E2E.hasSecondaryBrowserAccount(),
			'E2E_USER2 / E2E_USER2_PASS / E2E_USER2_APP_PASSWORD not configured; user-share spec skipped.',
		)

		// 1. As A: create the pad through the regular UI flow.
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)

		// Capture the fileid for the revoke-then-direct-URL assertion.
		const fileId = await propfindFileId(padName)
		expect(fileId).toBeGreaterThan(0)

		// 2. As A: grant user B read access via OCS.
		const share = await createUserReadShare(padName, E2E.secondaryUser!)
		shareId = share.id

		// 3. As B: separate browser context using the pre-built secondary
		// session (created by the setup project), open the shared pad.
		const userBCtx = await browser.newContext({ storageState: SECONDARY_STATE_FILE })
		const userB = await userBCtx.newPage()
		try {
			await gotoSharedWithMe(userB)
			await expectFileInList(userB, padName)

			await openPadFromFileList(userB, padName)
			await expectEtherpadViewerMounted(userB)
			await closeViewer(userB)

			// 4. As A: revoke the share via OCS.
			await deleteShareById(shareId)
			shareId = ''

			// 5a. As B: the row is gone from "Shared with me".
			await gotoSharedWithMe(userB)
			await expect(
				userB.locator(`[data-cy-files-list-row-name="${padName}"]`).first(),
			).toBeHidden({ timeout: 30_000 })

			// 5b. As B: navigating directly to the file id must not surface
			// the Etherpad viewer. NC will either redirect to the user's
			// own root (file not in scope) or render an empty state — both
			// are acceptable; the assertion is that no Etherpad iframe
			// gets mounted for user B.
			await userB.goto(`${E2E.baseURL}/apps/files/?fileid=${fileId}`)
			await userB.waitForTimeout(2_000)
			await expect(userB.locator('iframe[title="Etherpad"]').first()).toBeHidden({ timeout: 10_000 })
		} finally {
			await userBCtx.close()
		}
	})
})
