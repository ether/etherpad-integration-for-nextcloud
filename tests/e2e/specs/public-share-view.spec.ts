/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers native Viewer routing and access isolation for public file and folder shares. */

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
	propfindFileId,
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
 * Confusable names prove that the share opens the selected file, not merely
 * any pad whose viewer can mount. Write access preserves the created pad URL
 * for that comparison.
 */
test.describe('public folder share with confusable file names', () => {
	const folderName = uniqueName('public-folder-plus-space')
	// The parent carries the run id because these names must differ by one character.
	const plusName = 'A+B.pad'
	const spaceName = 'A B.pad'
	let shareToken = ''
	let shareUrl = ''
	let plusPad = { path: '', padUrl: '' }
	let spacePad = { path: '', padUrl: '' }
	let plusFileId = 0
	let outsideFileId = 0
	const outsideName = `${uniqueName('public-folder-outsider')}.txt`

	test.beforeAll(async () => {
		await mkcolViaDav(folderName)
		// Wait until the newly created folder is resolvable on slower storage.
		await propfindFileId(folderName)
		plusPad = await createPadAtPath(`/${folderName}/${plusName}`)
		spacePad = await createPadAtPath(`/${folderName}/${spaceName}`)
		plusFileId = await propfindFileId(`${folderName}/${plusName}`)
		await putFileViaDav(outsideName, 'outside the share')
		outsideFileId = await propfindFileId(outsideName)
		const share = await createPublicShare(folderName, SHARE_PERMISSION_READ_WRITE)
		shareToken = share.token
		shareUrl = share.url
	})

	test.afterAll(async () => {
		// Always remove the run-tagged parent, even when share revocation fails.
		try {
			if (shareToken !== '') {
				await deletePublicShare(shareToken)
			}
		} finally {
			// Keep both cleanups independent.
			try {
				await deleteViaDav(outsideName)
			} finally {
				await deleteViaDav(folderName)
			}
		}
	})

	test('refuses a file id from outside the share, with no path fallback', async ({ browser }) => {
		expect(outsideFileId).toBeGreaterThan(0)

		const publicContext = await browser.newContext()
		try {
			// A path fallback would open the real pad named alongside the foreign id.
			const response = await publicContext.request.get(
				`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${shareToken}`
				+ `?fileId=${outsideFileId}&file=${encodeURIComponent(plusName)}`,
			)

			expect(response.status()).toBe(404)
			expect(await response.text()).not.toContain(plusPad.padUrl)
		} finally {
			await publicContext.close()
		}
	})

	test('opens plus and space filenames from a public folder share without confusing their pads', async ({ browser }) => {
		expect(plusPad.path).toBe(`/${folderName}/${plusName}`)
		expect(spacePad.path).toBe(`/${folderName}/${spaceName}`)
		expect(plusPad.padUrl).not.toBe(spacePad.padUrl)

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)
			const sharePath = new URL(shareUrl).pathname

			// Capture the request generated by Nextcloud's own file-list click.
			const [openRequest] = await Promise.all([
				publicPage.waitForRequest((request) => request.url().includes('/api/v1/public/open/')),
				openPadFromFileList(publicPage, plusName),
			])

			await expectEtherpadViewerMounted(publicPage)
			await expect(publicPage.getByRole('dialog', { name: plusName })).toBeVisible()
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(plusPad.padUrl)
			expect(new URL(openRequest.url()).searchParams.get('fileId')).toBe(String(plusFileId))
			await closeViewer(publicPage)
			await expect.poll(() => new URL(publicPage.url()).pathname).toBe(sharePath)
			await expect(
				publicPage.locator(`[data-cy-files-list-row-name="${plusName}"]`).first(),
			).toBeVisible()

			await openPadFromFileList(publicPage, spaceName)
			await expectEtherpadViewerMounted(publicPage)
			await expect(publicPage.getByRole('dialog', { name: spaceName })).toBeVisible()
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(spacePad.padUrl)
		} finally {
			await publicContext.close()
		}
	})

	test('keeps compatibility links to pads inside a public folder share working', async ({ browser }) => {
		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(
				`${E2E.baseURL}/apps/etherpad_nextcloud/public/${encodeURIComponent(shareToken)}`
				+ `?file=${encodeURIComponent(`/${plusName}`)}`,
			)

			await expectEtherpadViewerMounted(publicPage)
			await expect(publicPage.getByRole('dialog', { name: plusName })).toBeVisible()
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(plusPad.padUrl)
		} finally {
			await publicContext.close()
		}
	})
})
