/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	closeViewer,
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	expectRecoveryCardForCopy,
	followOpenTheOriginal,
	readEtherpadUrlFromViewer,
	openPadFromFileList,
	rememberAndFollowOpenTheOriginal,
	uniquePadName,
} from '../fixtures/nextcloud'
import { copyViaDav, deleteViaDav, propfindFileId } from '../fixtures/dav'

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
	const aliasCopies: string[] = []

	test.afterAll(async () => {
		for (const name of aliasCopies) {
			await deleteViaDav(name)
		}
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
	 * The opt-in from the same card: ticking it writes `alias_of_pad_id`
	 * into the copy, so opening the copy again goes straight to the
	 * original instead of asking once more.
	 */
	test('remembers the original so a later open skips the card', async ({ page }) => {
		// Its own source and copy rather than the ones above, so this test
		// stands on its own when run alone.
		const aliasOriginal = uniquePadName('orphan-alias-source')
		const aliasCopy = uniquePadName('orphan-alias-copy')
		aliasCopies.push(aliasCopy, aliasOriginal)

		await gotoFiles(page)
		await createPublicPad(page, aliasOriginal)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)
		const originalFileId = await propfindFileId(aliasOriginal)
		await copyViaDav(aliasOriginal, aliasCopy)

		await gotoFiles(page)
		await openPadFromFileList(page, aliasCopy)
		await expectRecoveryCardForCopy(page, { originalFound: true })
		await rememberAndFollowOpenTheOriginal(page, originalFileId)

		const originalPadUrl = await readEtherpadUrlFromViewer(page)

		// Second open of the same copy: no card, and the frame carries the
		// original's pad. The browser URL still names the copy — the alias
		// is resolved server-side in the open payload, so the viewer never
		// learns that it happened and never navigates.
		await closeViewer(page)
		await gotoFiles(page)
		await openPadFromFileList(page, aliasCopy)
		await expectEtherpadViewerMounted(page)
		await expect(page.locator('.epnc-native-error-message')).toHaveCount(0)
		expect(await readEtherpadUrlFromViewer(page)).toBe(originalPadUrl)
	})
})
