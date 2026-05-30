/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test } from '@playwright/test'
import {
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	expectRecoveryCardForCopy,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import { copyViaDav, deleteViaDav } from '../fixtures/dav'

/**
 * Recovery flow for a `.pad` file that has no binding row of its own —
 * the common path is a user duplicating the file in the Files app, which
 * server-side creates a new file id without copying the binding.
 *
 * We reproduce that exact state via a WebDAV server-side COPY (no DB or
 * occ access needed), then verify the viewer mounts the recovery card
 * with the "Open the original" affordance — proving find-original
 * resolves the source and the user is not silently routed into a wrong
 * pad.
 */
test.describe('orphan .pad recovery', () => {
	const original = uniquePadName('orphan-source')
	const copy = uniquePadName('orphan-copy')

	test.afterAll(async () => {
		await deleteViaDav(copy)
		await deleteViaDav(original)
	})

	test('opens the recovery card with an Open-the-original affordance for a WebDAV-copied .pad', async ({ page }) => {
		await gotoFiles(page)

		// Create the source pad via the regular UI flow; the create path
		// writes frontmatter and the binding row that the copy will
		// intentionally lack.
		await createPublicPad(page, original)
		await expectEtherpadViewerMounted(page)
		await page.goto(page.url())

		// Server-side COPY — the destination receives a new fileid but
		// the binding row stays attached to the source. The destination
		// is therefore a genuine orphan from the viewer's perspective.
		await copyViaDav(original, copy)

		await gotoFiles(page)
		await openPadFromFileList(page, copy)

		await expectRecoveryCardForCopy(page, { originalFound: true })
	})
})
