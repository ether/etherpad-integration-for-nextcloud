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
	SHARE_PERMISSION_READ,
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

/**
 * A visitor of a public link, signed in nowhere. A context left without a
 * storageState is signed in as the test user: the project's state fills
 * it in, and a public page then behaves as it does for its owner.
 */
const SIGNED_OUT = { storageState: { cookies: [], origins: [] } }

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

		const publicContext = await browser.newContext(SIGNED_OUT)
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

	test('sends a signed-out visitor of a viewer link to the login and back', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext(SIGNED_OUT)
		browserNoise.watch(publicContext)
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/by-id/1`)

			await expect(publicPage).toHaveURL(/\/login\?redirect_url=[^&]*by-id%2F1|\/login\?redirect_url=[^&]*by-id\/1/)
			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
		} finally {
			await publicContext.close()
		}
	})

	/**
	 * The app's old address for a share with a password hands on to the
	 * share page, which asks for it. Nextcloud answered 404 while that
	 * address was served behind its own check of the share.
	 */
	test('hands the old address of a share with a password on to its password page', async ({ browser, browserNoise }) => {
		const name = uniquePadName('public-share-password')
		await putFileViaDav(name, 'A pad behind a password.')
		// Nextcloud keeps a link share whose file is deleted, and its token
		// would lead on to the password page until a job sweeps it.
		let token = ''
		try {
			token = (await createPublicShare(name, SHARE_PERMISSION_READ, `E2e-${Math.random().toString(36).slice(2)}-Share!`)).token
			const publicContext = await browser.newContext(SIGNED_OUT)
			browserNoise.watch(publicContext)
			try {
				const publicPage = await publicContext.newPage()
				const answer = await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/public/${encodeURIComponent(token)}`)

				expect(answer?.status()).toBe(200)
				await expect(publicPage).toHaveURL(new RegExp(`/s/${token}/authenticate`))
				await expect(publicPage.locator('input[type="password"]')).toBeVisible()
			} finally {
				await publicContext.close()
			}
		} finally {
			try {
				if (token !== '') {
					await deletePublicShare(token)
				}
			} finally {
				await deleteViaDav(name, { pastTrash: true })
			}
		}
	})

	test('rejects invalid public share tokens without pad data', async ({ browser, browserNoise }) => {
		const publicContext = await browser.newContext(SIGNED_OUT)
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
		const publicContext = await browser.newContext(SIGNED_OUT)
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

		const publicContext = await browser.newContext(SIGNED_OUT)
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

		const publicContext = await browser.newContext(SIGNED_OUT)
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

		const publicContext = await browser.newContext(SIGNED_OUT)
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

		const publicContext = await browser.newContext(SIGNED_OUT)
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
		const publicContext = await browser.newContext(SIGNED_OUT)
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
	let token = ''

	// The share first: Nextcloud keeps a link share whose file is deleted.
	test.afterAll(async () => {
		await deletePublicShare(token).catch(() => {})
		await deleteViaDav(name).catch(() => {})
	})

	test('shows what the pad says, and hands out neither the pad nor a session', async () => {
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)
		const marker = `read through a link ${Date.now()}`
		await etherpadApiPost('setText', { padID: padId, text: marker })
		const share = await createPublicReadShare(name)
		token = share.token

		const visitor = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const opened = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${encodeURIComponent(share.token)}`)
			const answer = await opened.text()
			expect(opened.status(), answer).toBe(200)
			const body = JSON.parse(answer) as { url?: string, original_pad_url?: string, is_readonly_view?: boolean, content_url?: string }
			expect(body.url, 'a read-only link should hand out no pad address').toBe('')
			expect(body.original_pad_url, 'in no field').toBe('')
			// Nor under a name a later field might give it: the pad's id is
			// nowhere in the answer.
			expect(answer, 'nor the pad\'s id').not.toContain(padId)
			expect(body.is_readonly_view).toBe(true)
			const sessions = opened.headersArray().filter((header) => header.name.toLowerCase() === 'set-cookie' && header.value.startsWith('sessionID='))
			expect(sessions, 'nor an Etherpad session').toEqual([])

			expect(body.content_url, 'it should say where to read the pad').toBeTruthy()
			const content = await visitor.get(new URL(body.content_url!, E2E.baseURL).toString())
			expect(content.status()).toBe(200)
			expect(await content.text(), 'what the pad says, from the pad server').toContain(marker)

			// The app's old address for the link hands on to Nextcloud's share
			// page for the same token; it shows nothing of its own.
			const page = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/public/${encodeURIComponent(share.token)}`, { maxRedirects: 0 })
			expect(page.status(), 'the app\'s old address for the link').toBe(303)
			expect(page.headers().location ?? '', 'and hands on to the share it belongs to').toContain(`/s/${share.token}`)
		} finally {
			await visitor.dispose()
		}
	})
})

/**
 * A writable link to a protected pad opens as the link's own Etherpad
 * author. Its visitors share the session made for the link within the
 * hour, so opening it again and again does not fill Etherpad with
 * sessions - and one Etherpad no longer has is not handed out again. The
 * container stack has a memory cache (APCu), which the keeping needs.
 */
test.describe('a writable public link to a protected pad', () => {
	test.skip(E2E.etherpadApi === null, 'Needs E2E_ETHERPAD_URL and E2E_ETHERPAD_API_KEY; only the container stack has them.')

	test('hands its visitors one Etherpad session, and a new one when Etherpad drops it', async () => {
		const name = uniquePadName('public-writable-protected')
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const groupID = padIdOfPadUrl(pad.padUrl).split('$')[0]
		const sessionsOfGroup = async (): Promise<string[]> =>
			Object.keys(await etherpadApiPost<Record<string, unknown> | null>('listSessionsOfGroup', { groupID }) ?? {})
		let token = ''
		const visitor = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			token = (await createPublicShare(name, SHARE_PERMISSION_READ_WRITE)).token
			const open = async (): Promise<string> => {
				const answer = await visitor.get(`${E2E.baseURL}/apps/etherpad_nextcloud/api/v1/public/open/${encodeURIComponent(token)}`)
				expect(answer.status()).toBe(200)
				return decodeURIComponent(/sessionID=([^;]+)/.exec(answer.headers()['set-cookie'] ?? '')?.[1] ?? '')
			}
			const before = await sessionsOfGroup()

			const handedOut = [await open(), await open(), await open(), await open(), await open()]

			expect(new Set(handedOut).size, 'every open the same session').toBe(1)
			expect(handedOut[0], 'a session at all').not.toBe('')
			expect((await sessionsOfGroup()).filter((id) => !before.includes(id)), 'one new session in the pad\'s group').toEqual([handedOut[0]])

			await etherpadApiPost('deleteSession', { sessionID: handedOut[0] })
			const next = await open()
			expect(next, 'not the session Etherpad no longer has').not.toBe(handedOut[0])
			expect(next).not.toBe('')
		} finally {
			await visitor.dispose()
			try {
				await deletePublicShare(token)
			} finally {
				await deleteViaDav(name, { pastTrash: true })
			}
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
	let token = ''

	test.afterAll(async () => {
		await deletePublicShare(token).catch(() => {})
		await deleteViaDav(folder).catch(() => {})
	})

	test('answers that it has no pad, with nothing a visitor could act on', async () => {
		await mkcolViaDav(folder)
		const original = uniquePadName('public-copy-original')
		const copy = uniquePadName('public-copy')
		await createPadAtPath(`/${folder}/${original}`, 'public')
		await copyViaDav(`${folder}/${original}`, `${folder}/${copy}`)
		const share = await createPublicReadShare(folder)
		token = share.token

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
