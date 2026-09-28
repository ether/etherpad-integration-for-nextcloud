/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import { E2E } from '../fixtures/env'
import { createPadAtPath, createUserReadShare, deleteShareById, deleteViaDav, getFileViaDav, padApiPost, propfindFileId, restoreFromTrashViaDav } from '../fixtures/dav'
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
	const padWithSavedText = async (name: string, accessMode: string): Promise<{ fileId: number, padId: string, marker: string }> => {
		const pad = await createPadAtPath(`/${name}`, accessMode)
		const fileId = await propfindFileId(name)
		const padId = padIdOfPadUrl(pad.padUrl)
		const marker = `saved before the loss ${Date.now()}`
		await etherpadApiPost('setText', { padID: padId, text: marker })
		const synced = await padApiPost(`pads/sync/${fileId}`)
		expect(synced.status, JSON.stringify(synced.body)).toBe(200)
		return { fileId, padId, marker }
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

	test('a public pad Etherpad made anew, empty, counts as lost', async () => {
		const name = uniquePadName('lost-remade')
		try {
			const { fileId, padId, marker } = await padWithSavedText(name, 'public')
			await etherpadApiPost('deletePad', { padID: padId })
			// What a visit to the pad's address does once it is gone.
			await etherpadApiPost('createPad', { padID: padId })

			await expectRecovered(name, fileId, padId, marker)
		} finally {
			await deleteViaDav(name)
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
