/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test } from '../fixtures/browser-noise'
import {
	gotoFiles,
	createBlankPadFromTemplatePicker,
	expectEtherpadViewerMounted,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav } from '../fixtures/dav'

/**
 * Smoke flow #2 (issue #54): create a pad via Nextcloud's native
 * TemplateFileCreator flow ("+ New pad" -> blank template). This is the
 * path that emits FileCreatedFromTemplateEvent and must initialize the
 * .pad frontmatter before the first viewer open.
 */
test.describe('template-picker pad create + open', () => {
	const padName = uniquePadName('template-create')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates a blank pad via the template picker and opens it', async ({ page }) => {
		await gotoFiles(page)
		await createBlankPadFromTemplatePicker(page, padName)

		await expectEtherpadViewerMounted(page)
	})
})
