/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { createPadAtPath, deleteViaDav, mkcolViaDav, propfindFileId } from '../fixtures/dav'
import { uniqueName } from '../fixtures/nextcloud'

/**
 * Every protected pad lives in its own Etherpad group, and a session grants
 * access to one group. The `sessionID` cookie is the only place that state
 * lives, so an open that writes just its own id revokes the others: a second
 * protected pad in a second tab used to take the first tab's access away, and
 * Etherpad answered 403 for it.
 *
 * The unit tests pin the rules against a stubbed session listing. This is the
 * part they cannot reach — that Etherpad's real response has the shape the
 * parser expects, that a real browser cookie survives ten opens of the other
 * pad, and that Etherpad itself still grants access at the end of it. A
 * mismatch in the real payload would leave every unit test green and restore
 * the original bug in full.
 */
test.describe('protected pads opened side by side', () => {
	const folderName = uniqueName('multi-session')
	let padA = { path: '', padUrl: '', fileId: 0 }
	let padB = { path: '', padUrl: '', fileId: 0 }

	test.beforeAll(async () => {
		await mkcolViaDav(folderName)
		await propfindFileId(folderName)
		const a = await createPadAtPath(`/${folderName}/pad-a.pad`, 'protected')
		const b = await createPadAtPath(`/${folderName}/pad-b.pad`, 'protected')
		padA = { ...a, fileId: await propfindFileId(`${folderName}/pad-a.pad`) }
		padB = { ...b, fileId: await propfindFileId(`${folderName}/pad-b.pad`) }
	})

	test.afterAll(async () => {
		await deleteViaDav(folderName)
	})

	test('keeps the first pad reachable after ten opens of the second', async () => {
		// storageState cleared: request.newContext() inherits the project's
		// `use`, and the saved browser session would authenticate instead of
		// the app password — and carry a sessionID cookie from another test.
		const ctx = await playwrightRequest.newContext({
			baseURL: E2E.baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: 'Basic ' + Buffer.from(`${E2E.user}:${E2E.appPassword}`).toString('base64'),
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
			},
		})

		try {
			const open = async (fileId: number) => {
				const res = await ctx.post('/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id', {
					form: { fileId: String(fileId) },
				})
				expect(res.status(), await res.text()).toBe(200)
			}

			await open(padA.fileId)
			for (let i = 0; i < 10; i++) {
				await open(padB.fileId)
			}

			const state = await ctx.storageState()
			const sessionCookie = state.cookies.find((cookie) => cookie.name === 'sessionID')
			expect(sessionCookie, 'the open response should set a sessionID cookie').toBeTruthy()

			// One id per pad, not one per open. Ten opens of B used to leave
			// ten of B's ids in the cookie and push A's out.
			const ids = decodeURIComponent(sessionCookie!.value).split(',')
			expect(ids).toHaveLength(2)

			// And Etherpad agrees: pad A is still readable with that cookie.
			// This is the assertion the unit tests cannot make — it is the
			// server's own verdict on the value we built.
			const padAUrl = new URL(padA.padUrl)
			const exportUrl = `${padAUrl.origin}${padAUrl.pathname}/export/txt`
			const exported = await ctx.get(exportUrl)
			expect(exported.status(), `pad A should still be reachable, got HTTP ${exported.status()}`).toBe(200)
		} finally {
			await ctx.dispose()
		}
	})
})
