/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers native Viewer routing and access isolation for public file and folder shares. */

import { request as playwrightRequest } from '@playwright/test'
import { test, expect } from '../fixtures/browser-noise'
import { E2E } from '../fixtures/env'
import { etherpadApiPost, padIdOfPadUrl } from '../fixtures/etherpad'
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
	copyViaDav,
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

	test('opens a shared public pad without authenticated storage state', async ({ page, browser, browserNoise }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)

		const share = await createPublicReadShare(padName)
		shareToken = share.token
		shareUrl = share.url

		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)
			await expect(publicPage.locator('.viewer__content, .viewer, [data-cy-viewer]').first()).toBeVisible({ timeout: 30_000 })
			await expect(publicPage.locator('iframe').first()).toBeVisible({ timeout: 30_000 })
		} finally {
			await publicContext.close()
		}
	})

	test('does not expose internal viewer data without login', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/by-id/1`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByRole('heading', { name: /could not open pad|pad konnte nicht geöffnet werden/i })).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})

	test('rejects invalid public share tokens without pad data', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

	test('renders an error page for invalid public viewer tokens', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/public/not-a-real-e2e-token?file=/Missing.pad`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByText(/share not found|freigabe nicht gefunden/i)).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})

	test('rejects non-pad public shares without pad data', async ({ browser, browserNoise }) => {
		await putFileViaDav(textFileName, 'This is not a managed pad.')
		const share = await createPublicReadShare(textFileName)
		nonPadShareToken = share.token

		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

	test('does not mount Etherpad for non-pad public viewer shares', async ({ browser, browserNoise }) => {
		await putFileViaDav(textRouteFileName, 'This is not a managed pad.')
		const share = await createPublicReadShare(textRouteFileName)
		nonPadRouteShareToken = share.token

		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

	test('refuses a file id from outside the share, with no path fallback', async ({ browser, browserNoise }) => {
		expect(outsideFileId).toBeGreaterThan(0)

		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

	test('opens plus and space filenames from a public folder share without confusing their pads', async ({ browser, browserNoise }) => {
		expect(plusPad.path).toBe(`/${folderName}/${plusName}`)
		expect(spacePad.path).toBe(`/${folderName}/${spaceName}`)
		expect(plusPad.padUrl).not.toBe(spacePad.padUrl)

		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

	test('keeps compatibility links to pads inside a public folder share working', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext()
		browserNoise.watch(publicContext)
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

/**
 * A read-only link to a protected pad: the visitor reads what the pad says
 * and gets no way to write - neither the pad's address nor an Etherpad
 * session, which would let them edit whatever the share said.
 */
test.describe('a read-only public link to a protected pad', () => {
	test.skip(E2E.etherpadApi === null, 'Needs E2E_ETHERPAD_URL and E2E_ETHERPAD_API_KEY; only the container stack has them.')
	const name = uniquePadName('public-readonly-protected')

	test.afterAll(async () => {
		await deleteViaDav(name).catch(() => {})
	})

	test('shows what the pad says, and hands out neither the pad nor a session', async () => {
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const marker = `read through a link ${Date.now()}`
		await etherpadApiPost('setText', { padID: padIdOfPadUrl(pad.padUrl), text: marker })
		const share = await createPublicReadShare(name)

		const visitor = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const opened = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${encodeURIComponent(share.token)}`)
			expect(opened.status(), await opened.text()).toBe(200)
			const body = await opened.json() as { url?: string, is_readonly_view?: boolean, content_url?: string }
			expect(body.url, 'a read-only link should hand out no pad address').toBe('')
			expect(body.is_readonly_view).toBe(true)
			const sessions = opened.headersArray().filter((header) => header.name.toLowerCase() === 'set-cookie' && header.value.startsWith('sessionID='))
			expect(sessions, 'nor an Etherpad session').toEqual([])

			expect(body.content_url, 'it should say where to read the pad').toBeTruthy()
			const content = await visitor.get(new URL(body.content_url!, E2E.baseURL).toString())
			expect(content.status()).toBe(200)
			expect(await content.text(), 'what the pad says, from the pad server').toContain(marker)

			// The app's own page for the link, as a single-file share opens it:
			// shown, or handed on to Nextcloud's share page for the same token.
			const page = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/public/${encodeURIComponent(share.token)}`, { maxRedirects: 0 })
			expect([200, 303], `the app's public page answered ${page.status()}`).toContain(page.status())
			if (page.status() === 303) {
				expect(page.headers().location ?? '', 'and hands on to the share it belongs to').toContain(`/s/${share.token}`)
			}
		} finally {
			await visitor.dispose()
		}
	})
})

/**
 * A copy of a pad in a folder shared by link has no pad of its own. The
 * visitor is told so, with nothing their client could act on: no code that
 * would start a recovery, which needs a signed-in user, and no pad address.
 */
test.describe('a copy of a pad in a public folder share', () => {
	const folder = uniqueName('public-folder-copy')

	test.afterAll(async () => {
		await deleteViaDav(folder).catch(() => {})
	})

	test('answers that it has no pad, with nothing a visitor could act on', async () => {
		await mkcolViaDav(folder)
		const original = uniquePadName('public-copy-original')
		const copy = uniquePadName('public-copy')
		await createPadAtPath(`/${folder}/${original}`, 'public')
		await copyViaDav(`${folder}/${original}`, `${folder}/${copy}`)
		const share = await createPublicReadShare(folder)

		const visitor = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const opened = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${encodeURIComponent(share.token)}?file=${encodeURIComponent(copy)}`)
			const text = await opened.text()
			expect(opened.status(), text).toBe(400)
			const body = JSON.parse(text) as { code?: string, url?: string, message?: string }
			expect(body.code, 'no code a visitor\'s client would act on').toBeUndefined()
			expect(body.url, 'and no pad').toBeUndefined()
			expect(body.message).toBe('This .pad file has no pad in this Nextcloud. Its owner can open it to restore the pad.')
		} finally {
			await visitor.dispose()
		}
	})
})
