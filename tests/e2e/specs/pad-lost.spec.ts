/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '../fixtures/browser-noise'
import { E2E } from '../fixtures/env'
import { createPadAtPath, createUserReadShare, deleteShareById, deleteViaDav, getFileViaDav, padApiPost, propfindFileId, putFileViaDav, restoreFromTrashViaDav } from '../fixtures/dav'
import { etherpadApiPost, padIdOfPadUrl } from '../fixtures/etherpad'
import { expectEtherpadViewerMounted, gotoFiles, openPadFromFileList, uniquePadName } from '../fixtures/nextcloud'

/**
 * A pad Etherpad has lost - deleted there, or made anew empty by a visit
 * to a public pad's address - is not opened as if nothing happened: the
 * open says so (`pad_missing`), and a new pad is made from the content
 * saved in the file, which from then on opens it.
 *
 * Losing a pad takes Etherpad's own API, so these run where the spec knows
 * it: the container stack.
 */
test.describe('a pad Etherpad has lost', () => {
	test.skip(E2E.etherpadApi === null, 'Needs E2E_ETHERPAD_URL and E2E_ETHERPAD_API_KEY; only the container stack has them.')

	/** A pad whose text is saved in its file: a snapshot with revisions, as any pad someone wrote in has. */
	const padWithSavedText = async (name: string, accessMode: string): Promise<{ fileId: number, padId: string, padUrl: string, marker: string }> => {
		const pad = await createPadAtPath(`/${name}`, accessMode)
		const fileId = await propfindFileId(name)
		const padId = padIdOfPadUrl(pad.padUrl)
		const marker = `saved before the loss ${Date.now()}`
		await etherpadApiPost('setText', { padID: padId, text: marker })
		const synced = await padApiPost(`pads/sync/${fileId}`)
		expect(synced.status, JSON.stringify(synced.body)).toBe(200)
		return { fileId, padId, padUrl: pad.padUrl, marker }
	}

	/** The open refuses the lost pad, the recovery makes a new one with the saved text, and the file opens it. */
	const expectRecovered = async (name: string, fileId: number, padId: string, marker: string): Promise<void> => {
		const opened = await padApiPost('pads/open-by-id', { fileId: String(fileId) })
		expect(opened.status, JSON.stringify(opened.body)).toBe(400)
		expect((opened.body as { code?: string }).code).toBe('pad_missing')

		const recovered = await padApiPost(`pads/recover-from-snapshot/${fileId}`)
		expect(recovered.status, JSON.stringify(recovered.body)).toBe(200)
		const newPadId = String((recovered.body as { new_pad_id?: string }).new_pad_id ?? '')
		expect(newPadId).not.toBe('')
		expect(newPadId).not.toBe(padId)

		const reopened = await padApiPost('pads/open-by-id', { fileId: String(fileId) })
		expect(reopened.status, JSON.stringify(reopened.body)).toBe(200)
		const text = await etherpadApiPost<{ text: string }>('getText', { padID: newPadId })
		expect(text.text).toContain(marker)
		expect(await getFileViaDav(name), 'the file names the new pad').toContain(newPadId)
		await expectSyncWritesTheNewPad(name, fileId, newPadId)
	}

	/**
	 * The new pad starts its revisions anew, under a file whose snapshot
	 * counted the old pad's. What is written into it after that reaches the
	 * file with an ordinary sync all the same.
	 */
	const expectSyncWritesTheNewPad = async (name: string, fileId: number, newPadId: string): Promise<void> => {
		const written = `written into the new pad ${Date.now()}`
		await etherpadApiPost('setText', { padID: newPadId, text: written })
		const synced = await padApiPost(`pads/sync/${fileId}`)
		expect(synced.status, JSON.stringify(synced.body)).toBe(200)
		expect((synced.body as { status?: string }).status).toBe('updated')
		expect(await getFileViaDav(name), 'the file holds what was written into the new pad').toContain(written)
	}

	/**
	 * An editor still open on the file syncs it, forced, as it closes: the
	 * pad made anew is not written over the file, which keeps its content
	 * for the new pad.
	 */
	const expectForcedSyncRefused = async (name: string, fileId: number, marker: string): Promise<void> => {
		const synced = await padApiPost(`pads/sync/${fileId}?force=1`)
		expect(synced.status, JSON.stringify(synced.body)).toBe(400)
		expect((synced.body as { code?: string }).code).toBe('pad_missing')
		expect(await getFileViaDav(name), 'the file keeps its content').toContain(marker)
	}

	for (const accessMode of ['public', 'protected']) {
		test(`a ${accessMode} pad deleted in Etherpad is made anew from its file`, async () => {
			const name = uniquePadName(`lost-${accessMode}`)
			try {
				const { fileId, padId, marker } = await padWithSavedText(name, accessMode)
				// A protected pad's group stays: a session could still be made.
				await etherpadApiPost('deletePad', { padID: padId })

				await expectRecovered(name, fileId, padId, marker)
			} finally {
				await deleteViaDav(name)
			}
		})
	}

	/**
	 * A recovery writes the file and moves its row: someone the file is
	 * shared with to read may not, whatever the endpoint is asked. The file
	 * stays as it is, and its owner makes the new pad as before.
	 */
	test('a reader of a shared file may not make its new pad', async () => {
		test.skip(!E2E.hasSecondaryAccount(), 'Needs a second account to share with.')
		const name = uniquePadName('lost-shared')
		let shareId: string | null = null
		try {
			const { fileId, padId, marker } = await padWithSavedText(name, 'protected')
			shareId = (await createUserReadShare(name, E2E.secondaryUser!)).id
			await etherpadApiPost('deletePad', { padID: padId })

			const refused = await padApiPost(`pads/recover-from-snapshot/${fileId}`, null, { uid: E2E.secondaryUser!, password: E2E.secondaryAppPassword! })
			expect(refused.status, JSON.stringify(refused.body)).toBe(403)
			expect(await getFileViaDav(name), 'the file still names its pad').toContain(padId)

			await expectRecovered(name, fileId, padId, marker)
		} finally {
			if (shareId !== null) {
				await deleteShareById(shareId)
			}
			await deleteViaDav(name)
		}
	})

	/**
	 * A pad made from a template holds the template's content in its file
	 * from the start, before any sync. Lost then - made anew, empty, by a
	 * visit to its address - it counts as lost like any other, and the new
	 * pad holds the template's content, rather than a sync writing the
	 * empty pad over it.
	 */
	test('a public pad made from a template counts as lost before its first sync', async () => {
		const templateName = uniquePadName('lost-template-src')
		const name = uniquePadName('lost-from-template')
		let padId = ''
		try {
			const template = await padWithSavedText(templateName, 'public')
			const created = await padApiPost('pads/from-template', { file: `/${name}`, templateFileId: String(template.fileId) })
			expect(created.status, JSON.stringify(created.body)).toBe(200)
			const fileId = await propfindFileId(name)
			padId = String((created.body as { pad_id?: string }).pad_id ?? '')
			expect(padId).not.toBe('')
			await etherpadApiPost('deletePad', { padID: padId })
			await etherpadApiPost('createPad', { padID: padId })

			await expectRecovered(name, fileId, padId, template.marker)
		} finally {
			await deleteViaDav(name)
			await deleteViaDav(templateName)
			// The pad made anew stays after a recovery, which leaves it alone.
			if (padId !== '') {
				await etherpadApiPost('deletePad', { padID: padId }).catch(() => {})
			}
		}
	})

	/**
	 * 1.1.0-beta.1 wrote a template's content into the file with
	 * `snapshot_rev: 0`. That is saved content all the same: the pad made
	 * anew by a visit counts as lost, and the new pad holds the template's
	 * content.
	 */
	test('a public pad made from a template by 1.1.0-beta.1 counts as lost', async () => {
		const templateName = uniquePadName('lost-beta1-template-src')
		const name = uniquePadName('lost-beta1-from-template')
		let padId = ''
		try {
			const template = await padWithSavedText(templateName, 'public')
			const created = await padApiPost('pads/from-template', { file: `/${name}`, templateFileId: String(template.fileId) })
			expect(created.status, JSON.stringify(created.body)).toBe(200)
			const fileId = await propfindFileId(name)
			padId = String((created.body as { pad_id?: string }).pad_id ?? '')
			expect(padId).not.toBe('')
			const content = await getFileViaDav(name)
			expect(content).toMatch(/^snapshot_rev: [1-9]\d*$/m)
			await putFileViaDav(name, content.replace(/^snapshot_rev: \d+$/m, 'snapshot_rev: 0'))
			expect(await getFileViaDav(name)).toMatch(/^snapshot_rev: 0$/m)
			await etherpadApiPost('deletePad', { padID: padId })
			await etherpadApiPost('createPad', { padID: padId })

			await expectForcedSyncRefused(name, fileId, template.marker)
			await expectRecovered(name, fileId, padId, template.marker)
		} finally {
			await deleteViaDav(name)
			await deleteViaDav(templateName)
			if (padId !== '') {
				await etherpadApiPost('deletePad', { padID: padId }).catch(() => {})
			}
		}
	})

	/**
	 * What a visit to a missing public pad's address does: Etherpad makes
	 * it anew at revision 0, with its default text and the visitor as its
	 * author. That counts as lost, and the new pad holds the saved text.
	 */
	test('a public pad a visit made anew counts as lost', async ({ page }) => {
		const name = uniquePadName('lost-visited')
		let padId = ''
		try {
			const saved = await padWithSavedText(name, 'public')
			padId = saved.padId
			await etherpadApiPost('deletePad', { padID: padId })

			await page.goto(saved.padUrl)
			await page.waitForSelector('iframe[name="ace_outer"]', { timeout: 20_000 })
			await expect.poll(async () => {
				try {
					return (await etherpadApiPost<{ revisions: number }>('getRevisionsCount', { padID: padId })).revisions
				} catch {
					return null
				}
			}, { message: 'the visit should have made the pad anew' }).toBe(0)

			await expectForcedSyncRefused(name, saved.fileId, saved.marker)
			await expectRecovered(name, saved.fileId, padId, saved.marker)
		} finally {
			await deleteViaDav(name)
			// The pad made anew stays after a recovery, which leaves it alone.
			if (padId !== '') {
				await etherpadApiPost('deletePad', { padID: padId }).catch(() => {})
			}
		}
	})

	/**
	 * An admin making the pad anew through the API, with Etherpad's default
	 * text: the same rule as the visit above, and the case the docs name for
	 * admins, without a browser.
	 */
	test('a public pad made anew through the API counts as lost', async () => {
		const name = uniquePadName('lost-remade')
		let padId = ''
		try {
			const saved = await padWithSavedText(name, 'public')
			padId = saved.padId
			await etherpadApiPost('deletePad', { padID: padId })
			await etherpadApiPost('createPad', { padID: padId })

			await expectRecovered(name, saved.fileId, padId, saved.marker)
		} finally {
			await deleteViaDav(name)
			// The pad made anew stays after a recovery, which leaves it alone.
			if (padId !== '') {
				await etherpadApiPost('deletePad', { padID: padId }).catch(() => {})
			}
		}
	})

	/**
	 * A file back from the trash is surely the one its pad was, so a pad
	 * Etherpad lost while the file was away is made anew by the restore
	 * itself, from the file's content: the next open finds it, no card.
	 */
	test('a restore makes a pad Etherpad lost while the file was in the trash anew', async () => {
		const name = uniquePadName('lost-in-trash')
		try {
			const { fileId, padId, marker } = await padWithSavedText(name, 'public')
			await deleteViaDav(name)
			await etherpadApiPost('deletePad', { padID: padId })

			await restoreFromTrashViaDav(name)

			const opened = await padApiPost('pads/open-by-id', { fileId: String(fileId) })
			expect(opened.status, JSON.stringify(opened.body)).toBe(200)
			const newPadId = String((opened.body as { pad_id?: string }).pad_id ?? '')
			expect(newPadId).not.toBe(padId)
			const text = await etherpadApiPost<{ text: string }>('getText', { padID: newPadId })
			expect(text.text).toContain(marker)
			expect(await getFileViaDav(name), 'the file names the new pad').toContain(newPadId)
			await expectSyncWritesTheNewPad(name, fileId, newPadId)
		} finally {
			await deleteViaDav(name)
		}
	})

	test('the viewer offers a new pad and then opens it', async ({ page }) => {
		const name = uniquePadName('lost-viewer')
		try {
			const { padId } = await padWithSavedText(name, 'public')
			await etherpadApiPost('deletePad', { padID: padId })

			await gotoFiles(page)
			await openPadFromFileList(page, name)
			await expect(page.getByText('This pad is no longer on the Etherpad server.')).toBeVisible({ timeout: 30_000 })
			await page.getByRole('button', { name: 'Create new pad from this file' }).click()
			await expectEtherpadViewerMounted(page)
		} finally {
			await deleteViaDav(name)
		}
	})
})
