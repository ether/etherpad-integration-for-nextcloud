/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import { E2E } from '../fixtures/env'
import {
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createPublicReadShare,
	deletePublicShare,
	deleteViaDav,
	putFileViaDav,
} from '../fixtures/dav'

test.describe('public share access without login', () => {
	const padName = uniquePadName('public-share')
	const textFileName = `e2e-public-share-non-pad-${Date.now()}.txt`
	const textRouteFileName = `e2e-public-share-non-pad-route-${Date.now()}.txt`
	let shareToken = ''
	let nonPadShareToken = ''
	let nonPadRouteShareToken = ''
	let shareUrl = ''

	test.afterAll(async () => {
		await deletePublicShare(shareToken)
		await deletePublicShare(nonPadShareToken)
		await deletePublicShare(nonPadRouteShareToken)
		await deleteViaDav(padName)
		await deleteViaDav(textFileName)
		await deleteViaDav(textRouteFileName)
	})

	test('opens a shared public pad without authenticated storage state', async ({ page, browser }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)

		const share = await createPublicReadShare(padName)
		shareToken = share.token
		shareUrl = share.url

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)
			await expect(publicPage.locator('.viewer__content, .viewer, [data-cy-viewer]').first()).toBeVisible({ timeout: 30_000 })
			await expect(publicPage.locator('iframe').first()).toBeVisible({ timeout: 30_000 })
		} finally {
			await publicContext.close()
		}
	})

	test('does not expose internal viewer data without login', async ({ browser }) => {
		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/by-id/1`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByRole('heading', { name: /could not open pad|pad konnte nicht geöffnet werden/i })).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})

	test('rejects invalid public share tokens without pad data', async ({ browser }) => {
		const publicContext = await browser.newContext()
		try {
			const response = await publicContext.request.get(
				`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/not-a-real-e2e-token?file=/Missing.pad`,
			)
			const body = await response.text()

			expect(response.status()).toBeGreaterThanOrEqual(400)
			expect(body).not.toMatch(/"url"\s*:/)
			expect(body).not.toMatch(/"pad_url"\s*:/)
		} finally {
			await publicContext.close()
		}
	})

	test('renders an error page for invalid public viewer tokens', async ({ browser }) => {
		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/public/not-a-real-e2e-token?file=/Missing.pad`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByText(/share not found|freigabe nicht gefunden/i)).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})

	test('rejects non-pad public shares without pad data', async ({ browser }) => {
		await putFileViaDav(textFileName, 'This is not a managed pad.')
		const share = await createPublicReadShare(textFileName)
		nonPadShareToken = share.token

		const publicContext = await browser.newContext()
		try {
			const response = await publicContext.request.get(
				`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${encodeURIComponent(nonPadShareToken)}`,
			)
			const body = await response.text()

			expect(response.status()).toBeGreaterThanOrEqual(400)
			expect(body).not.toMatch(/"url"\s*:/)
			expect(body).not.toMatch(/"pad_url"\s*:/)
		} finally {
			await publicContext.close()
		}
	})

	test('does not mount Etherpad for non-pad public viewer shares', async ({ browser }) => {
		await putFileViaDav(textRouteFileName, 'This is not a managed pad.')
		const share = await createPublicReadShare(textRouteFileName)
		nonPadRouteShareToken = share.token

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/public/${encodeURIComponent(nonPadRouteShareToken)}`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByText('This is not a managed pad.')).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})
})
