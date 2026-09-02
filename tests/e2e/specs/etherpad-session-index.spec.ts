/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test'
import { E2E } from '../fixtures/env'

/**
 * The assumption the expired-session collector rests on: deleting a
 * session removes its id from the author's index, so the listing that
 * walks that index gets shorter.
 *
 * It is not free to assume. Etherpad's `deleteSession` updates the index
 * with `setSub(..., undefined)`, and there are reports of the key
 * surviving on some versions and storage backends — leaving an id the
 * listing still walks and `deleteSession` will no longer take, because it
 * answers that the session does not exist. A collector running against
 * such a server would report clean sweeps forever while the cost it
 * exists to remove stayed exactly where it was.
 *
 * So this asks the pad server directly, and counts the **raw** keys of the
 * API response rather than what the app's client hands back: the client
 * drops entries it cannot read, which is precisely what a surviving key
 * looks like.
 */
test.describe('Etherpad session index', () => {
	const api = async (
		ctx: APIRequestContext,
		endpoint: string,
		form: Record<string, string>,
	): Promise<Record<string, unknown>> => {
		const res = await ctx.post(`${E2E.etherpadApi!.url}/api/1.2.15/${endpoint}`, {
			form: { apikey: E2E.etherpadApi!.key, ...form },
		})
		expect(res.status(), endpoint).toBe(200)
		const payload = await res.json() as { code: number, message: string, data: Record<string, unknown> }
		expect(payload.code, `${endpoint}: ${payload.message}`).toBe(0)
		return payload.data
	}

	test('deleting a session takes its id out of the author index', async () => {
		test.skip(E2E.etherpadApi === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured.')

		const ctx = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		let groupId = ''
		try {
			groupId = String((await api(ctx, 'createGroup', {})).groupID)
			const authorId = String((await api(ctx, 'createAuthorIfNotExistsFor', {
				authorMapper: `nc:index-probe-${Date.now()}`,
			})).authorID)

			const validUntil = String(Math.floor(Date.now() / 1000) + 3600)
			const created: string[] = []
			for (let i = 0; i < 4; i++) {
				created.push(String((await api(ctx, 'createSession', { groupID: groupId, authorID: authorId, validUntil })).sessionID))
			}

			// Raw keys, including any the server can no longer describe.
			const indexKeys = async (): Promise<string[]> =>
				Object.keys(await api(ctx, 'listSessionsOfAuthor', { authorID: authorId }) ?? {})

			expect(await indexKeys()).toHaveLength(4)

			await api(ctx, 'deleteSession', { sessionID: created[0] })

			const after = await indexKeys()
			expect(
				after,
				'the deleted id is still in the author index — collecting cannot shrink this server\'s listing',
			).toHaveLength(3)
			expect(after).not.toContain(created[0])
		} finally {
			if (groupId !== '') {
				await ctx.post(`${E2E.etherpadApi!.url}/api/1.2.15/deleteGroup`, {
					form: { apikey: E2E.etherpadApi!.key, groupID: groupId },
				}).catch(() => {})
			}
			await ctx.dispose()
		}
	})
})
