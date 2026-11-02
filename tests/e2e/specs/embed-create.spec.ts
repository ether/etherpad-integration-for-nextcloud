/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { test, expect } from '../fixtures/browser-noise'
import { gotoFiles, uniqueName, uniquePadName } from '../fixtures/nextcloud'
import { deleteViaDav, existsViaDav, mkcolViaDav, propfindFileId } from '../fixtures/dav'

test.describe('embed create', () => {
	const folder = uniqueName('embed-create')
	let launcher = ''

	test.beforeAll(async () => {
		await mkcolViaDav(folder)
		launcher = `/index.php/apps/etherpad_nextcloud/embed/create-by-parent/${await propfindFileId(folder)}`
	})

	test.afterAll(async () => {
		await deleteViaDav(folder).catch(() => {})
	})

	test('creates nothing from a link opened on its own', async ({ page }) => {
		const padName = uniquePadName('embed-create-link')

		await page.goto(`${launcher}?name=${encodeURIComponent(padName)}&accessMode=protected`)

		await expect(page.locator('[data-epnc-embed-create-error]')).toBeVisible()
		expect(await existsViaDav(`${folder}/${padName}`)).toBe(false)
	})

	/** The control: without it the test above would pass for a page that never creates. */
	test('creates inside an iframe', async ({ page }) => {
		const padName = uniquePadName('embed-create-framed')
		await gotoFiles(page)

		await page.evaluate((src) => {
			const frame = document.createElement('iframe')
			frame.src = src
			document.body.append(frame)
		}, `${launcher}?name=${encodeURIComponent(padName)}&accessMode=protected`)

		await expect.poll(() => existsViaDav(`${folder}/${padName}`), { timeout: 30_000 }).toBe(true)
	})
})
