/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { createPadAtPath, deleteViaDav, findTrashbinEntry, padApiPost, propfindFileId, purgeTrashbinEntry } from '../fixtures/dav'
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
 *
 * A delete through WebDAV holds the file's lock, so the trash cannot write
 * a fresh snapshot and keeps the pad - with its group - until the file is
 * gone for good. The group goes then, when the pending pads are settled.
 */
test.describe('protected pad cleanup on the Etherpad side', () => {
	const padName = uniquePadName('group-cleanup')

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
	})

	test('takes the Etherpad group with it once the file is gone for good', async () => {
		const etherpad = E2E.etherpadApi
		test.skip(etherpad === null, 'E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY not configured; Etherpad-side spec skipped.')

		const api = await playwrightRequest.newContext({ storageState: { cookies: [], origins: [] } })
		// POST, not GET: a query string carries the api key into proxy and
		// access logs, and — with `trace: 'retain-on-failure'` and the html
		// reporter — into the report CI uploads as an artifact.
		// EtherpadClient posts every authenticated call for the same reason.
		// Not a secret-safe channel either way: a trace can hold the body
		// too. What makes that acceptable is the key, not the method — it
		// comes from the checked-in APIKEY.txt of a throwaway stack.
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

			// In the trash, the pad is kept as it is: a restore takes it back.
			expect(await groupIds(), 'the trash keeps the pad and its group').toContain(decodeURIComponent(group))

			// Gone for good: out of the trash, then settled. delete_on_trash is
			// on in this stack, so settling removes the pad — and with it, the
			// group and its sessions.
			const entry = await findTrashbinEntry(padName)
			expect(entry, 'the .pad should be in the trash').not.toBeNull()
			await purgeTrashbinEntry(entry!)
			const settled = await padApiPost('admin/settle-pending')
			test.skip(settled.status === 403, 'E2E_USER is not a Nextcloud admin; the pending pads cannot be settled from here.')
			expect(settled.status, JSON.stringify(settled.body)).toBe(200)

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
