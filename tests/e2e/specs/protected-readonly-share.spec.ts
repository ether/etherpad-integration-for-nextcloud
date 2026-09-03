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
	gotoFilesDir,
	gotoSharedWithMe,
	openPadFromFileList,
	uniqueName,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createPadAtPath,
	createUserReadShare,
	createUserWriteShare,
	deleteShareById,
	deleteViaDav,
	mkcolViaDav,
	padApiPost,
	propfindFileId,
} from '../fixtures/dav'
import { etherpadApiPost, padIdOfPadUrl } from '../fixtures/etherpad'
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

	// Distinctive enough that finding it in the rendered view proves the
	// snapshot came from this pad rather than from an empty placeholder.
	const snapshotMarker = 'readonly-marker-8f3a1c'

	test('shows the snapshot, not an editable pad', async ({ page, browser }) => {
		test.skip(
			!E2E.hasSecondaryBrowserAccount(),
			'E2E_USER2 / E2E_USER2_PASS / E2E_USER2_APP_PASSWORD not configured; two-user spec skipped.',
		)

		const padName = uniquePadName('readonly-share')
		let shareId = ''
		const userBCtx = await browser.newContext({ storageState: SECONDARY_STATE_FILE })
		try {
			const pad = await createPadAtPath(`/${padName}`, 'protected')
			const fileId = await propfindFileId(padName)

			// Give the pad content and get it into the `.pad` file, both on
			// purpose. Without this the assertion below cannot tell a
			// rendered snapshot from the placeholder shown when there is
			// none — and the snapshot written on viewer close is fired off
			// unawaited, so waiting for it would be a race.
			await etherpadApiPost('setText', { padID: padIdOfPadUrl(pad.padUrl), text: snapshotMarker })
			const synced = await padApiPost(`pads/sync/${fileId}`)
			expect(synced.status, JSON.stringify(synced.body)).toBe(200)

			shareId = (await createUserReadShare(padName, E2E.secondaryUser!)).id

			const userB = await userBCtx.newPage()
			await gotoSharedWithMe(userB)
			await expectFileInList(userB, padName)
			await openPadFromFileList(userB, padName)
			await expectReadOnlySnapshotViewerMounted(userB, snapshotMarker)
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
	/**
	 * One file, two ways in, different permissions on each.
	 *
	 * A pad shared read-only on its own but living in a folder shared with
	 * write permission is reachable by both paths. This asserts the promise
	 * a user cares about: they may edit it, so they get the editor.
	 *
	 * It does not prove the writable-path preference. `getById`'s order is
	 * not controllable from here, and on this stack it returns the writable
	 * mount first regardless — removing the preference leaves this test
	 * green, which was checked rather than assumed. The preference itself is
	 * pinned in UserNodeResolverTest, where the order can be chosen.
	 */
	test('opens the editor when another path to the same pad may write', async ({ browser }) => {
		test.skip(
			!E2E.hasSecondaryBrowserAccount(),
			'E2E_USER2 / E2E_USER2_PASS / E2E_USER2_APP_PASSWORD not configured; two-user spec skipped.',
		)

		const folderName = uniqueName('overlap')
		const padName = uniquePadName('overlap')
		const shareIds: string[] = []
		const userBCtx = await browser.newContext({ storageState: SECONDARY_STATE_FILE })
		try {
			await mkcolViaDav(folderName)
			await createPadAtPath(`/${folderName}/${padName}`, 'protected')

			// Both at once: the folder writable, the file itself view-only.
			shareIds.push((await createUserWriteShare(folderName, E2E.secondaryUser!)).id)
			shareIds.push((await createUserReadShare(`${folderName}/${padName}`, E2E.secondaryUser!)).id)

			const userB = await userBCtx.newPage()
			await gotoFilesDir(userB, folderName)
			await expectFileInList(userB, padName)
			await openPadFromFileList(userB, padName)
			await expectEtherpadViewerMounted(userB)
			await expect(userB.locator('.epnc-native-snapshot')).toHaveCount(0)
			await userB.close()
		} finally {
			for (const id of shareIds) {
				await deleteShareById(id).catch(() => {})
			}
			await deleteViaDav(`${folderName}/${padName}`).catch(() => {})
			await deleteViaDav(folderName).catch(() => {})
		}
	})
})
