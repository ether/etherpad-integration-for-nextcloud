/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { expect, test } from '@playwright/test'
import { gotoAdminPadSettings, runAdminEtherpadHealthCheck } from '../fixtures/nextcloud'

/**
 * Smoke flow #4 (issue #54): verify that the saved admin settings can
 * reach Etherpad. This catches broken API keys, wrong Etherpad URLs and
 * server-side health-check regressions with the same browser flow an
 * administrator uses.
 */
test.describe('admin Etherpad health check', () => {
	test('tests the configured Etherpad connection', async ({ page }) => {
		const isAdmin = await gotoAdminPadSettings(page)
		test.skip(!isAdmin, 'E2E_USER is not a Nextcloud admin; admin health-check spec skipped.')
		await runAdminEtherpadHealthCheck(page)
	})
})

/**
 * The templates section takes its labels and URLs from the settings provider
 * and fills its list over the API. Both are invisible to the unit tests, which
 * mock the page around them — a missing label or a failing request shows up
 * only here, as an empty box between two headings.
 */
test.describe('admin shared templates section', () => {
	test('renders its labels and loads the template list', async ({ page }) => {
		const isAdmin = await gotoAdminPadSettings(page)
		test.skip(!isAdmin, 'E2E_USER is not a Nextcloud admin; admin templates spec skipped.')

		await expect(page.getByRole('heading', { name: /geteilte pad-vorlagen|shared pad templates/i })).toBeVisible()
		await expect(page.locator('#epnc-template-upload')).toHaveText(/\S/)

		// Either the list has entries or the empty note is shown; a failed load
		// leaves both empty and puts a message in the status line.
		const list = page.locator('#epnc-template-list li')
		const empty = page.locator('#epnc-template-empty')
		await expect
			.poll(async () => (await list.count()) > 0 || await empty.isVisible(), { timeout: 15_000 })
			.toBe(true)
		await expect(page.locator('#epnc-template-status')).toHaveText('')
	})
})
