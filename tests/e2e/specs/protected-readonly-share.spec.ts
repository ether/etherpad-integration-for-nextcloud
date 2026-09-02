/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	closeViewer,
	expectEtherpadViewerMounted,
	expectFileInList,
	expectReadOnlySnapshotViewerMounted,
	gotoFiles,
	gotoSharedWithMe,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createPadAtPath,
	createUserReadShare,
	createUserWriteShare,
	deleteShareById,
	deleteViaDav,
} from '../fixtures/dav'
import { E2E } from '../fixtures/env'
import { SECONDARY_STATE_FILE } from '../fixtures/auth'

/**
 * "Can view" has to mean it on the pad server too.
 *
 * Nextcloud's read-only share stops at the file. The pad lives on another
 * host, where the only things standing between somebody and the text are
 * the session and the URL this app hands them — so a share without write
 * permission that still opens the editor is a permission that exists in
 * the interface and nowhere else.
 */
test.describe('read-only share of a protected pad', () => {
	test.describe.configure({ timeout: 180_000 })

	test('shows the snapshot, not an editable pad', async ({ page, browser }) => {
		test.skip(
			!E2E.hasSecondaryBrowserAccount(),
			'E2E_USER2 / E2E_USER2_PASS / E2E_USER2_APP_PASSWORD not configured; two-user spec skipped.',
		)

		const padName = uniquePadName('readonly-share')
		let shareId = ''
		const userBCtx = await browser.newContext({ storageState: SECONDARY_STATE_FILE })
		try {
			await createPadAtPath(`/${padName}`, 'protected')

			// The owner opens it once, so the pad has content and a snapshot
			// to show — and so the editable case is known to work here.
			await gotoFiles(page)
			await openPadFromFileList(page, padName)
			await expectEtherpadViewerMounted(page)
			await closeViewer(page)

			shareId = (await createUserReadShare(padName, E2E.secondaryUser!)).id

			const userB = await userBCtx.newPage()
			await gotoSharedWithMe(userB)
			await expectFileInList(userB, padName)
			await openPadFromFileList(userB, padName)
			await expectReadOnlySnapshotViewerMounted(userB)
			await userB.close()
		} finally {
			if (shareId !== '') {
				await deleteShareById(shareId).catch(() => {})
			}
			await userBCtx.close()
			await deleteViaDav(padName).catch(() => {})
		}
	})

	/**
	 * The control. Without it the test above would pass just as well for a
	 * viewer that never opens a pad for anybody.
	 */
	test('still opens the pad when the same share may write', async ({ page, browser }) => {
		test.skip(
			!E2E.hasSecondaryBrowserAccount(),
			'E2E_USER2 / E2E_USER2_PASS / E2E_USER2_APP_PASSWORD not configured; two-user spec skipped.',
		)

		const padName = uniquePadName('writable-share')
		let shareId = ''
		const userBCtx = await browser.newContext({ storageState: SECONDARY_STATE_FILE })
		try {
			await createPadAtPath(`/${padName}`, 'protected')
			shareId = (await createUserWriteShare(padName, E2E.secondaryUser!)).id

			const userB = await userBCtx.newPage()
			await gotoSharedWithMe(userB)
			await expectFileInList(userB, padName)
			await openPadFromFileList(userB, padName)
			await expectEtherpadViewerMounted(userB)
			await expect(userB.locator('.epnc-native-snapshot')).toHaveCount(0)
			await userB.close()
		} finally {
			if (shareId !== '') {
				await deleteShareById(shareId).catch(() => {})
			}
			await userBCtx.close()
			await deleteViaDav(padName).catch(() => {})
		}
	})
})
