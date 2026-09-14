/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Covers the icon a `.pad` shows in the file list.
 *
 * The app no longer copies a file-type icon into Nextcloud's signed core
 * directory; the file list gets the pad glyph from the app's own preview
 * provider instead. Nothing else asserts that, so a preview provider that
 * stops answering would fall back to the generic mime icon unnoticed.
 */

import { test, expect } from '@playwright/test'
import { gotoFiles, createPublicPad, expectFileInList, uniquePadName } from '../fixtures/nextcloud'
import { deleteViaDav } from '../fixtures/dav'

test.describe('pad file-list icon', () => {
	const padName = uniquePadName('list-icon')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('renders the app preview rather than a core mime icon', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)

		// Navigating unmounts the viewer; closing it by its button is not
		// needed here and the shared helper can hit the navigation toggle.
		await gotoFiles(page)
		await expectFileInList(page, padName)

		const row = page.locator(`[data-cy-files-list-row-name="${padName}"]`).first()
		const image = row.locator('img').first()

		// toHaveAttribute retries: the row paints before its preview has been
		// fetched, and reading src once would race that.
		await expect(image).toHaveAttribute('src', /preview/, { timeout: 30_000 })
	})
})
