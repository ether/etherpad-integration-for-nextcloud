/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import { E2E } from '../fixtures/env'
import {
	gotoFiles,
	closeViewer,
	createPublicPad,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniquePadName,
	uniqueName,
} from '../fixtures/nextcloud'
import {
	SHARE_PERMISSION_READ_WRITE,
	createPadAtPath,
	createPublicReadShare,
	createPublicShare,
	deletePublicShare,
	deleteViaDav,
	mkcolViaDav,
	putFileViaDav,
} from '../fixtures/dav'

test.describe('public share access without login', () => {
	const padName = uniquePadName('public-share')
	const textFileName = uniqueName('public-share-non-pad', 'txt')
	const textRouteFileName = uniqueName('public-share-non-pad-route', 'txt')
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

/**
 * A public folder share is the one place where a pad is opened purely by
 * name: there is no file id in play, the anonymous visitor's browser puts
 * the name in a query parameter, and the server looks it up inside the
 * share. That makes it the sharpest test for names that survive a round
 * trip through form-encoding only if nothing decodes them twice.
 *
 * `A+B.pad` and `A B.pad` differ in exactly one character, and that
 * character is the one form-encoding overloads. Both files exist, both
 * are real pads, and the pad each one points at is recorded before the
 * share is even created — so "a viewer appeared" cannot pass for
 * "the right document opened".
 *
 * The share is handed out with edit rights on purpose. A read-only public
 * share opens the read-only pad id instead, whose address is derived
 * server-side and cannot be compared against what create returned.
 */
test.describe('public folder share with confusable file names', () => {
	const folderName = uniqueName('public-folder-plus-space')
	// Deliberately not fixture names: they have to differ in one
	// character, and a timestamp in each would differ in fourteen. The
	// folder around them carries the run id, and deleting it takes both
	// with it as a single trash entry the sweep recognises.
	const plusName = 'A+B.pad'
	const spaceName = 'A B.pad'
	let shareToken = ''
	let shareUrl = ''
	let plusPadUrl = ''
	let spacePadUrl = ''

	test.beforeAll(async () => {
		await mkcolViaDav(folderName)
		plusPadUrl = (await createPadAtPath(`/${folderName}/${plusName}`)).padUrl
		spacePadUrl = (await createPadAtPath(`/${folderName}/${spaceName}`)).padUrl
		const share = await createPublicShare(folderName, SHARE_PERMISSION_READ_WRITE)
		shareToken = share.token
		shareUrl = share.url
	})

	test.afterAll(async () => {
		if (shareToken !== '') {
			await deletePublicShare(shareToken)
		}
		await deleteViaDav(folderName)
	})

	test('opens plus and space filenames from a public folder share without confusing their pads', async ({ browser }) => {
		// If create had already collapsed the two names, the second create
		// would have hit the first file and both would carry one address.
		// Everything below would then compare a value against itself.
		expect(plusPadUrl).not.toBe(spacePadUrl)

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)

			// Clicked in Nextcloud's own rendering of the share. The test
			// never builds the viewer URL itself — the encoding of the name
			// on the way to the server is exactly what is under test.
			await openPadFromFileList(publicPage, plusName)
			await expectEtherpadViewerMounted(publicPage)
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(plusPadUrl)
			await closeViewer(publicPage)

			await openPadFromFileList(publicPage, spaceName)
			await expectEtherpadViewerMounted(publicPage)
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(spacePadUrl)
		} finally {
			await publicContext.close()
		}
	})
})
