/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { createPadAtPath, deleteViaDav, propfindFileId } from '../fixtures/dav'
import { uniquePadName } from '../fixtures/nextcloud'

/**
 * A protected pad is a pad inside an Etherpad group, plus the sessions that
 * grant access to that group. Deleting the pad alone left the group and
 * every session ever issued for it behind, and nothing in the app collected
 * them afterwards — invisible from Nextcloud, and growing.
 *
 * The unit tests pin which API call is made. This asks the pad server
 * whether the group is actually gone, which is the only place that can
 * answer it.
 */
test.describe('protected pad cleanup on the Etherpad side', () => {
	const padName = uniquePadName('group-cleanup')

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('takes the Etherpad group with it when the pad is deleted', async () => {
		const etherpad = E2E.etherpadApi
		test.skip(etherpad === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured; Etherpad-side spec skipped.')

		const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		// POST, not GET: a query string carries the api key into proxy logs,
		// and — with `trace: 'retain-on-failure'` and the html reporter — into
		// the report CI uploads as an artifact. EtherpadClient posts every
		// authenticated call for the same reason.
		const groupIds = async (): Promise<string[]> => {
			const res = await api.post(`${etherpad!.url}/api/1.2.15/listAllGroups`, { form: { apikey: etherpad!.key } })
			expect(res.status()).toBe(200)
			const payload = await res.json() as { code: number, data?: { groupIDs?: string[] } }
			expect(payload.code, JSON.stringify(payload)).toBe(0)
			return payload.data?.groupIDs ?? []
		}

		try {
			const before = await groupIds()

			const pad = await createPadAtPath(`/${padName}`, 'protected')
			await propfindFileId(padName)
			const group = pad.padUrl.split('/p/').pop()?.split('%24')[0] ?? ''
			expect(group, `expected a group pad, got ${pad.padUrl}`).toMatch(/^g\./)

			const afterCreate = await groupIds()
			expect(afterCreate, 'creating a protected pad should add its group').toContain(decodeURIComponent(group))

			await deleteViaDav(padName)

			// delete_on_trash is on in this stack, so the trash move removes
			// the pad — and with it, the group and its sessions.
			await expect.poll(groupIds, { timeout: 20_000 })
				.not.toContain(decodeURIComponent(group))
			// Not a count: another suite run against the same instance may add
			// groups of its own, and this must fail for a leak rather than
			// for company.
			expect(await groupIds(), 'no other group should have been touched')
				.toEqual(expect.arrayContaining(before))
		} finally {
			await api.dispose()
		}
	})
})
