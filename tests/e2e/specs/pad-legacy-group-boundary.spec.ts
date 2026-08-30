/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import {
	createPadAtPath,
	deleteViaDav,
	getFileViaDav,
	padApiPost,
	propfindFileId,
	putFileViaDav,
} from '../fixtures/dav'
import { uniquePadName } from '../fixtures/nextcloud'

/**
 * A legacy Ownpad `.pad` names its own pad id, and the migration binds what
 * it names. For a group pad that id decides which Etherpad group a session
 * is later minted for, so it is a boundary and not just a lookup.
 *
 * Naming someone else's real pad is refused by the unique `pad_id` — it
 * collides with their binding. Naming their group with a pad name nobody
 * has used collides with nothing, and the session issued on open would
 * grant access to everything in that group. The check is that the pad is
 * actually in the group it claims; the unit tests pin the call, and this
 * asks a real Etherpad and a real Nextcloud whether the file migrates.
 */
test.describe('legacy migration and the group a pad id claims', () => {
	const probeName = uniquePadName('legacy-boundary-probe')
	const realName = uniquePadName('legacy-real-group-pad')
	const forgedName = uniquePadName('legacy-forged-suffix')
	let groupId = ''

	test.afterAll(async () => {
		await deleteViaDav(probeName).catch(() => {})
		await deleteViaDav(realName).catch(() => {})
		await deleteViaDav(forgedName).catch(() => {})
		const etherpad = E2E.etherpadApi
		if (etherpad !== null && groupId !== '') {
			const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
			await api.post(`${etherpad.url}/api/1.2.15/deleteGroup`, { form: { apikey: etherpad.key, groupID: groupId } })
				.catch(() => {})
			await api.dispose()
		}
	})

	test('migrates a pad that is in its group and refuses one that is not', async () => {
		const etherpad = E2E.etherpadApi
		test.skip(etherpad === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured; Etherpad-side spec skipped.')

		const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		const ep = async (method: string, form: Record<string, string>): Promise<Record<string, unknown>> => {
			const res = await api.post(`${etherpad!.url}/api/1.2.15/${method}`, {
				form: { apikey: etherpad!.key, ...form },
			})
			expect(res.status()).toBe(200)
			const payload = await res.json() as { code: number, data?: Record<string, unknown> }
			expect(payload.code, `${method}: ${JSON.stringify(payload)}`).toBe(0)
			return payload.data ?? {}
		}

		// The origin the app is configured with, learned from a pad it made
		// itself — a legacy URL only reaches the managed branch when it
		// points at the same server.
		const probe = await createPadAtPath(`/${probeName}`, 'public')
		const origin = new URL(probe.padUrl).origin

		// A group pad this app knows nothing about: exactly the shape a
		// migrated Ownpad pad has.
		groupId = String((await ep('createGroup', {})).groupID ?? '')
		expect(groupId).toMatch(/^g\./)
		const realPadId = String((await ep('createGroupPad', { groupID: groupId, padName: 'ownpad-era' })).padID ?? '')
		expect(realPadId).toBe(`${groupId}$ownpad-era`)

		await putFileViaDav(realName, `[InternetShortcut]\nURL=${origin}/p/${realPadId}\n`)
		const realFileId = await propfindFileId(realName)
		const migrated = await padApiPost(`pads/initialize-by-id/${realFileId}`)
		expect(migrated.status, JSON.stringify(migrated.body)).toBe(200)
		const realContent = await getFileViaDav(realName)
		expect(realContent).not.toContain('[InternetShortcut]')
		expect(realContent).toContain(realPadId)

		// Same group, a pad name nobody has used. Nothing in Nextcloud
		// collides with it, and before the check this bound and then minted
		// a session for the group above.
		await putFileViaDav(forgedName, `[InternetShortcut]\nURL=${origin}/p/${groupId}$never-created\n`)
		const forgedFileId = await propfindFileId(forgedName)
		const refused = await padApiPost(`pads/initialize-by-id/${forgedFileId}`)
		expect(refused.status, JSON.stringify(refused.body)).toBe(400)

		// Refused means untouched: the file is still the legacy shortcut, so
		// the next open retries rather than inheriting a half-migration.
		expect(await getFileViaDav(forgedName)).toContain('[InternetShortcut]')

		// And nothing was added to the group it named.
		const pads = (await ep('listPads', { groupID: groupId })).padIDs
		expect(pads).toEqual([realPadId])

		await api.dispose()
	})
})
