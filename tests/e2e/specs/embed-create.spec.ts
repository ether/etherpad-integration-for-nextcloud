/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { test, expect } from '../fixtures/browser-noise'
import { E2E } from '../fixtures/env'
import { gotoFiles, uniqueName, uniquePadName } from '../fixtures/nextcloud'
import { deleteViaDav, existsViaDav, mkcolViaDav, propfindFileId } from '../fixtures/dav'

test.describe('embed create', () => {
	const folder = uniqueName('embed-create')
	let launcher = ''

	test.beforeAll(async () => {
		await mkcolViaDav(folder)
		launcher = `${E2E.baseURL}/apps/etherpad_nextcloud/embed/create-by-parent/${await propfindFileId(folder)}`
	})

	test.afterAll(async () => {
		await deleteViaDav(folder).catch(() => {})
	})

	test('creates nothing from a link opened on its own', async ({ page }) => {
		const padName = uniquePadName('embed-create-link')
		const creates: string[] = []
		page.on('request', (request) => {
			if (request.method() === 'POST' && request.url().includes('create-by-parent')) {
				creates.push(request.url())
			}
		})

		await page.goto(`${launcher}?name=${encodeURIComponent(padName)}&accessMode=protected`)

		const refusal = await page.locator('#etherpad-nextcloud-embed-create').getAttribute('data-l10n-not-embedded')
		await expect(page.locator('[data-epnc-embed-create-error-message]')).toHaveText(String(refusal))
		expect(creates).toEqual([])
		expect(await existsViaDav(`${folder}/${padName}`)).toBe(false)
	})

	/** The control: without it the test above would pass for a page that never creates. */
	test('creates inside an iframe', async ({ page }) => {
		const padName = uniquePadName('embed-create-framed')
		await gotoFiles(page)

		const told = await page.evaluate((src) => new Promise<string>((resolve) => {
			window.addEventListener('message', (event) => {
				const type = String(event.data?.type)
				if (type.startsWith('epnc:create-')) {
					resolve(type)
				}
			})
			setTimeout(() => resolve('no answer'), 30_000)
			const frame = document.createElement('iframe')
			frame.src = src
			document.body.append(frame)
		}), `${launcher}?name=${encodeURIComponent(padName)}&accessMode=protected`)

		expect(told).toBe('epnc:create-succeeded')
		expect(await existsViaDav(`${folder}/${padName}`)).toBe(true)
	})
})
