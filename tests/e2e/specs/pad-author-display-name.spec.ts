/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test } from '../fixtures/browser-noise'
import {
	createBlankPadFromTemplatePicker,
	expectEtherpadCurrentUserName,
	expectEtherpadViewerMounted,
	gotoFiles,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getCurrentUserDisplayName } from '../fixtures/dav'

/**
 * Protected pads should open with an Etherpad session mapped to the
 * Nextcloud account's display name. Public pads intentionally do not
 * send a personal name to Etherpad, so this spec uses the native
 * protected "New pad" template flow.
 */
test.describe('protected pad author display name', () => {
	const padName = uniquePadName('author-name')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('passes the Nextcloud display name to Etherpad', async ({ page }) => {
		const displayName = await getCurrentUserDisplayName()

		await gotoFiles(page)
		await createBlankPadFromTemplatePicker(page, padName)

		await expectEtherpadViewerMounted(page)
		await expectEtherpadCurrentUserName(page, displayName)
	})
})
