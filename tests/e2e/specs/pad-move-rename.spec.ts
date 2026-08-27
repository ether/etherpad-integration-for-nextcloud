/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test } from '@playwright/test'
import {
	closeViewer,
	createPublicPad,
	expectEtherpadViewerMounted,
	gotoFiles,
	gotoFilesDir,
	openPadFromFileList,
	uniquePadName,
	uniqueName,
} from '../fixtures/nextcloud'
import { deleteViaDav, mkcolViaDav, moveViaDav } from '../fixtures/dav'

/**
 * A `.pad`'s binding is keyed on the Nextcloud file id, which survives a
 * rename or a move (WebDAV MOVE preserves the id). So the pad must still
 * open from its new name / location without re-provisioning. These specs
 * guard that the binding is not accidentally tied to path or name.
 */
test.describe('pad rename keeps the binding', () => {
	const original = uniquePadName('rename-before')
	const renamed = uniquePadName('rename-after')

	test.afterAll(async () => {
		await deleteViaDav(renamed).catch(() => {})
		await deleteViaDav(original).catch(() => {})
	})

	test('reopens after an in-place rename', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, original)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)

		// Rename in the same directory; the file id (and thus binding) is kept.
		await moveViaDav(original, renamed)

		await gotoFiles(page)
		await openPadFromFileList(page, renamed)
		await expectEtherpadViewerMounted(page)
	})
})

test.describe('pad move keeps the binding', () => {
	const padName = uniquePadName('move')
	const folder = uniqueName('move-folder')

	test.afterAll(async () => {
		await deleteViaDav(`${folder}/${padName}`).catch(() => {})
		await deleteViaDav(folder).catch(() => {})
		await deleteViaDav(padName).catch(() => {})
	})

	test('reopens after moving into a subfolder', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)

		await mkcolViaDav(folder)
		await moveViaDav(padName, `${folder}/${padName}`)

		await gotoFilesDir(page, folder)
		await openPadFromFileList(page, padName)
		await expectEtherpadViewerMounted(page)
	})
})
