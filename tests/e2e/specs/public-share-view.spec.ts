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
 * `A+B.pad` and `A B.pad` differ in the one character a query string
 * overloads. Each pad's address is recorded before the share exists, so
 * "a viewer appeared" cannot pass for "the right document opened".
 *
 * The share is handed out with edit rights on purpose. A read-only public
 * share opens the read-only pad id instead, whose address is derived
 * server-side and cannot be compared against what create returned. That
 * makes the spec depend on `shareapi_allow_public_upload`, which
 * Nextcloud defaults to `yes` and tests/e2e/docker/up.sh sets explicitly;
 * against an instance that forbids public upload, the share create fails.
 *
 * Reading the frame with `iframe[title="Etherpad"]` is safe even with two
 * viewable pads in one folder: measured on Nextcloud 33, an open viewer
 * carries exactly one iframe — the Viewer does not mount a neighbour's
 * handler for prefetch. Were that ever untrue, the second assertion
 * expects a different address than the first, so a stale or neighbouring
 * frame fails the spec rather than passing it.
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
	let plusPad = { path: '', padUrl: '' }
	let spacePad = { path: '', padUrl: '' }
	let plusFileId = 0
	let outsideFileId = 0
	const outsideName = `${uniqueName('public-folder-outsider')}.txt`

	test.beforeAll(async () => {
		await mkcolViaDav(folderName)
		// PROPFIND before creating inside it. MKCOL answering 201 is not
		// quite the same as the folder being resolvable by the next
		// request — createUserReadShare in the same fixtures file carries a
		// comment about that class of lag on a CI runner — and the retry in
		// propfindFileId turns a race into a bounded wait.
		await propfindFileId(folderName)
		plusPad = await createPadAtPath(`/${folderName}/${plusName}`)
		spacePad = await createPadAtPath(`/${folderName}/${spaceName}`)
		plusFileId = await propfindFileId(`${folderName}/${plusName}`)
		// A real id the share does not contain; it need not be a pad.
		await putFileViaDav(outsideName, 'outside the share')
		outsideFileId = await propfindFileId(outsideName)
		const share = await createPublicShare(folderName, SHARE_PERMISSION_READ_WRITE)
		shareToken = share.token
		shareUrl = share.url
	})

	test.afterAll(async () => {
		// The folder delete is the only thing that can clean up these two
		// pads — their names carry no run id, so the trash sweep would not
		// recognise them on their own. It must not be skipped because
		// revoking the share threw.
		try {
			if (shareToken !== '') {
				await deletePublicShare(shareToken)
			}
		} finally {
			// Each on its own: a folder delete that throws must not take the
			// outsider's cleanup with it.
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
			// The path names a real pad here, so a fallback would answer
			// with it and the 404 is what says none happened.
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
		// Create gave back the names it was asked for, at the path it was
		// asked for. A rewrite here would be the same bug one step earlier,
		// and the comparison below would then be measuring the wrong thing.
		expect(plusPad.path).toBe(`/${folderName}/${plusName}`)
		expect(spacePad.path).toBe(`/${folderName}/${spaceName}`)
		// Two creates cannot share a pad id — an existing name is refused
		// with 409 rather than reused — so this only fails if the binding
		// side ever hands the same pad to two files. Cheap, and without it
		// every comparison below could be vacuously true.
		expect(plusPad.padUrl).not.toBe(spacePad.padUrl)

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)
			const sharePath = new URL(shareUrl).pathname

			// Clicked in Nextcloud's own rendering of the share, never a
			// URL this test built: what the click asks for is the point.
			const [openRequest] = await Promise.all([
				publicPage.waitForRequest((request) => request.url().includes('/api/v1/public/open/')),
				openPadFromFileList(publicPage, plusName),
			])

			await expectEtherpadViewerMounted(publicPage)
			await expect(publicPage.getByRole('dialog', { name: plusName })).toBeVisible()
			expect(await readEtherpadUrlFromViewer(publicPage)).toBe(plusPad.padUrl)
			// Parsed, not a substring: `fileId=12` is inside `fileId=123`.
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
