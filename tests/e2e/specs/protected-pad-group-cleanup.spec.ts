/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { createPadAtPath, deleteViaDav, findTrashbinEntry, getTrashbinEntryContent, padApiPost, propfindFileId } from '../fixtures/dav'
import { etherpadApiPost } from '../fixtures/etherpad'
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
 * a fresh snapshot and keeps the pad - with its group. The sweep finishes
 * the trash: the pad's content goes into the trashed file, and only then
 * do pad and group go.
 */
test.describe('protected pad cleanup on the Etherpad side', () => {
	const padName = uniquePadName('group-cleanup')
	const keptName = uniquePadName('group-kept')

	test.afterAll(async () => {
		await deleteViaDav(padName).catch(() => {})
		await deleteViaDav(keptName).catch(() => {})
	})

	test('takes the Etherpad group with it once the trashed file holds the pad', async () => {
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

		const groupOf = (padUrl: string): string => decodeURIComponent(padUrl.split('/p/').pop()?.split('%24')[0] ?? '')

		try {
			// A protected pad that stays in Files. The settle below finishes
			// every trash that waits, other suites' too, so a count or a list
			// taken before would fail for company; this one must survive it.
			const kept = await createPadAtPath(`/${keptName}`, 'protected')
			expect(groupOf(kept.padUrl), `expected a group pad, got ${kept.padUrl}`).toMatch(/^g\./)

			const pad = await createPadAtPath(`/${padName}`, 'protected')
			await propfindFileId(padName)
			const group = groupOf(pad.padUrl)
			expect(group, `expected a group pad, got ${pad.padUrl}`).toMatch(/^g\./)

			const afterCreate = await groupIds()
			expect(afterCreate, 'creating a protected pad should add its group').toContain(group)

			// An edit no snapshot holds yet: only the trash's own can save it.
			const padId = decodeURIComponent(pad.padUrl.split('/p/').pop() ?? '')
			const marker = `written before the trash ${Date.now()}`
			await etherpadApiPost('setText', { padID: padId, text: marker })

			await deleteViaDav(padName)
			const entry = await findTrashbinEntry(padName)
			expect(entry, 'the .pad should be in the trash').not.toBeNull()

			// What the background jobs do within minutes, so no check between
			// the delete and here: a cron run may have finished the trash
			// already. The end state proves the order either way - had the pad
			// gone before its snapshot, the marker would not be in the file.
			// delete_on_trash is on in this stack, so the pad goes, and with it
			// the group and its sessions.
			const settled = await padApiPost('admin/settle-pending')
			test.skip(settled.status === 403, 'E2E_USER is not a Nextcloud admin; the pending pads cannot be settled from here.')
			expect(settled.status, JSON.stringify(settled.body)).toBe(200)

			await expect.poll(groupIds, { timeout: 20_000 })
				.not.toContain(group)
			expect(await getTrashbinEntryContent(entry!), 'the trashed file should hold the pad as it was').toContain(marker)
			expect(await groupIds(), 'a pad still in Files should keep its group').toContain(groupOf(kept.padUrl))
		} finally {
			await api.dispose()
		}
	})
})
