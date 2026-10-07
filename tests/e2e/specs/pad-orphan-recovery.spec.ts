/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '../fixtures/browser-noise'
import {
	closeViewer,
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	expectRecoveryCardForCopy,
	followOpenTheOriginal,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import { copyViaDav, createPadAtPath, deleteViaDav, getFileViaDav, padApiGet, padApiPost, propfindFileId, restoreFromTrashViaDav } from '../fixtures/dav'

/**
 * Recovery flow for a `.pad` file that has no binding row of its own —
 * the common path is a user duplicating the file in the Files app, which
 * server-side creates a new file id without copying the binding.
 *
 * We reproduce that exact state via a WebDAV server-side COPY (no DB or
 * occ access needed), then verify the viewer mounts the recovery card
 * with the "Open the original" affordance — proving find-original
 * resolves the source and the user is not silently routed into a wrong
 * pad — and that following that affordance actually navigates to the
 * original pad.
 */
test.describe('orphan .pad recovery', () => {
	const original = uniquePadName('orphan-source')
	const copy = uniquePadName('orphan-copy')

	test.afterAll(async () => {
		await deleteViaDav(copy)
		await deleteViaDav(original)
	})

	test('shows the recovery card and follows "Open the original" to the source pad', async ({ page }) => {
		await gotoFiles(page)

		// Create the source pad via the regular UI flow; the create path
		// writes frontmatter and the binding row that the copy will
		// intentionally lack.
		await createPublicPad(page, original)
		await expectEtherpadViewerMounted(page)
		// Close the viewer so the source pad isn't held open while we copy
		// it (the create/sync path can otherwise still hold the lock).
		await closeViewer(page)
		const originalFileId = await propfindFileId(original)

		// Server-side COPY — the destination receives a new fileid but
		// the binding row stays attached to the source. The destination
		// is therefore a genuine orphan from the viewer's perspective.
		await copyViaDav(original, copy)

		await gotoFiles(page)
		await openPadFromFileList(page, copy)

		await expectRecoveryCardForCopy(page, { originalFound: true })

		// Following the affordance navigates to the original pad (mounts
		// the viewer, URL points at the original file id, not the copy).
		await followOpenTheOriginal(page, originalFileId)
	})

	/**
	 * A copy back from the trash is still a copy: a file without a row
	 * gets a pad of its own on restore only when no other file's row
	 * names its pad, so its open still offers the original.
	 */
	test('a copy restored from the trash still offers the original', async ({ page }) => {
		const source = uniquePadName('orphan-restored-source')
		const restored = uniquePadName('orphan-restored-copy')
		try {
			await gotoFiles(page)
			await createPublicPad(page, source)
			await expectEtherpadViewerMounted(page)
			await closeViewer(page)
			const sourceFileId = await propfindFileId(source)
			await copyViaDav(source, restored)

			await deleteViaDav(restored)
			await restoreFromTrashViaDav(restored)

			await gotoFiles(page)
			await openPadFromFileList(page, restored)
			await expectRecoveryCardForCopy(page, { originalFound: true })
			await followOpenTheOriginal(page, sourceFileId)
		} finally {
			await deleteViaDav(restored)
			await deleteViaDav(source)
		}
	})
})

/**
 * A copy names a pad another file's row holds. Asked to sync - by its
 * status, plainly or forced as an editor closing does - it says it has no
 * pad of its own, and nothing is written into it: the other file's pad
 * would otherwise land in the copy.
 */
test.describe('a copy of a .pad file and sync', () => {
	for (const accessMode of ['public', 'protected']) {
		test(`a copy of a ${accessMode} pad refuses to sync, and is left as it is`, async () => {
			const source = uniquePadName(`orphan-sync-source-${accessMode}`)
			const copy = uniquePadName(`orphan-sync-copy-${accessMode}`)
			try {
				await createPadAtPath(`/${source}`, accessMode)
				await copyViaDav(source, copy)
				const copyId = await propfindFileId(copy)
				const before = await getFileViaDav(copy)

				for (const [what, answer] of [
					['sync status', await padApiGet(`pads/sync-status/${copyId}`)],
					['sync', await padApiPost(`pads/sync/${copyId}`)],
					['forced sync', await padApiPost(`pads/sync/${copyId}?force=1`)],
				] as const) {
					expect(answer.status, `${what}: ${JSON.stringify(answer.body)}`).toBe(400)
					expect((answer.body as { code?: string }).code, what).toBe('missing_binding')
				}
				expect(await getFileViaDav(copy), 'the copy should be left as it is').toBe(before)
			} finally {
				await deleteViaDav(copy).catch(() => {})
				await deleteViaDav(source).catch(() => {})
			}
		})
	}
})
