/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import { E2E } from '../fixtures/env'
import {
	createPadAtPath,
	deleteViaDav,
	findTrashbinEntry,
	mkcolViaDav,
	moveViaDav,
	padApiPost,
	propfindFileId,
	purgeTrashbinEntry,
	restoreFromTrashViaDav,
} from '../fixtures/dav'
import { etherpadApiPost, groupExists, groupIdOfPadUrl, liveSessionsOfGroup, padExists, padIdOfPadUrl } from '../fixtures/etherpad'
import {
	addToGroup,
	createAccount,
	createGroup,
	createTeamFolder,
	deleteAccount,
	deleteGroup,
	deleteTeamFolder,
	isAppEnabled,
} from '../fixtures/admin-api'
import { uniqueName, uniquePadName } from '../fixtures/nextcloud'

/**
 * A pad goes once its `.pad` file is deleted for good, however that
 * happens: past the trash, from the trash, or with the account that owned
 * it (docs/deleting-pads.md), and whatever the file is called by then. A
 * file still in a trash keeps its pad.
 *
 * Whether a pad is gone only Etherpad can say, so these run where the
 * spec knows Etherpad's API: the container stack. What the background
 * jobs do within minutes, the admin's settle runs at once.
 */

const needsEtherpadApi = 'Needs E2E_ETHERPAD_URL and E2E_ETHERPAD_API_KEY; only the container stack has them.'

/** What the background jobs do within minutes, run now. */
const settle = async (): Promise<void> => {
	const settled = await padApiPost('admin/settle-pending')
	test.skip(settled.status === 403, 'E2E_USER is not a Nextcloud admin; the background jobs cannot be run from here.')
	expect(settled.status, JSON.stringify(settled.body)).toBe(200)
}

/**
 * How many rows the admin's consistency check counts as vanished: files
 * gone without a deletion the app heard of, whose pads it leaves.
 */
const vanishedFiles = async (): Promise<number> => {
	const checked = await padApiPost('admin/consistency-check')
	expect(checked.status, JSON.stringify(checked.body)).toBe(200)
	return Number((checked.body as { vanished_file_count?: unknown }).vanished_file_count)
}

/**
 * Nextcloud 34 reports the files in a removed folder under the wrong ids,
 * so the app cannot tell which they were and leaves their pads
 * (docs/deleting-pads.md). The fix, nextcloud/server#63998, is planned for
 * 34.0.5 as nextcloud/server#64497, not merged yet: should 34.0.5 come
 * without it, these cases fail there, and this check moves on.
 */
const misnumbersFolderFiles = async (): Promise<boolean> => {
	const res = await fetch(`${E2E.baseURL}/status.php`)
	const { version } = await res.json() as { version?: string }
	const [major, minor, patch] = String(version ?? '').split('.').map(Number)
	return major === 34 && minor === 0 && patch < 5
}
const misnumbered = 'Nextcloud 34 up to 34.0.4 reports the files in a removed folder under the wrong ids (nextcloud/server#63998, #64497).'

test.describe('pads of files deleted for good', () => {
	test.skip(E2E.etherpadApi === null, needsEtherpadApi)

	test('a protected pad goes with its file deleted past the trash', async () => {
		const name = uniquePadName('gone-past-trash')
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)
		expect(await padExists(padId), 'the pad should exist before the delete').toBe(true)

		await deleteViaDav(name, { pastTrash: true })
		expect(await findTrashbinEntry(name), 'the file should have skipped the trash').toBeNull()
		expect(await padExists(padId), 'the delete itself leaves Etherpad to the job').toBe(true)
		await settle()

		expect(await padExists(padId), 'the job should have deleted the pad').toBe(false)
		expect(await groupExists(groupIdOfPadUrl(pad.padUrl)), 'and its group with it').toBe(false)
	})

	/**
	 * The trash keeps a pad, and its group: a restore gives the file the
	 * same pad back. Deleted from the trash, the file takes pad and group.
	 */
	test('a pad file in the trash keeps its pad until it is deleted from there', async () => {
		const name = uniquePadName('gone-trashed-file')
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)
		const fileId = await propfindFileId(name)

		await deleteViaDav(name)
		expect(await findTrashbinEntry(name), 'the file should be in the trash').not.toBeNull()
		await settle()
		expect(await padExists(padId), 'the trash should keep the pad').toBe(true)
		expect(await groupExists(groupIdOfPadUrl(pad.padUrl)), 'and its group').toBe(true)

		await restoreFromTrashViaDav(name)
		await settle()
		const opened = await padApiPost('pads/open-by-id', { fileId: String(fileId) })
		expect(opened.status, JSON.stringify(opened.body)).toBe(200)
		expect((opened.body as { pad_id?: string }).pad_id, 'restored, the file should have the same pad').toBe(padId)

		await deleteViaDav(name)
		const entry = await findTrashbinEntry(name)
		expect(entry, 'the file should be in the trash again').not.toBeNull()
		await purgeTrashbinEntry(entry!)
		await settle()
		expect(await padExists(padId), 'deleted from the trash, the file should take its pad').toBe(false)
		expect(await groupExists(groupIdOfPadUrl(pad.padUrl)), 'and its group').toBe(false)
	})

	/**
	 * A delete, to the trash or past it, takes the sessions of the protected
	 * pads it takes along - a file's own, or those under a folder - while
	 * the trash keeps the pads.
	 */
	test('a protected pad moved to the trash loses its sessions', async () => {
		const name = uniquePadName('gone-sessions-file')
		const folder = uniqueName('gone-sessions-folder')
		await mkcolViaDav(folder)
		const pads = [await createPadAtPath(`/${name}`, 'protected'), await createPadAtPath(`/${folder}/${uniquePadName('inside')}`, 'protected')]
		for (const pad of pads) {
			const opened = await padApiPost('pads/open-by-id', { fileId: String(await propfindFileId(pad.path.replace(/^\/+/, ''))) })
			expect(opened.status, JSON.stringify(opened.body)).toBe(200)
			expect(await liveSessionsOfGroup(groupIdOfPadUrl(pad.padUrl)), `${pad.path} should have a session once opened`).toBeGreaterThan(0)
		}

		await deleteViaDav(name)
		await deleteViaDav(folder)

		for (const pad of pads) {
			expect(await liveSessionsOfGroup(groupIdOfPadUrl(pad.padUrl)), `${pad.path} should have lost its sessions`).toBe(0)
			expect(await padExists(padIdOfPadUrl(pad.padUrl)), `${pad.path} should keep its pad in the trash`).toBe(true)
		}
	})

	/**
	 * What counts is the file, not its name: a `.pad` renamed keeps its
	 * pad, and the pad goes once the file is deleted for good - past the
	 * trash, or from it.
	 */
	test('a renamed pad file takes its pad when it is deleted for good', async () => {
		const pastTrash = uniquePadName('gone-renamed-past')
		const fromTrash = uniquePadName('gone-renamed-trash')
		const pads = [await createPadAtPath(`/${pastTrash}`), await createPadAtPath(`/${fromTrash}`)]
		await moveViaDav(pastTrash, `${pastTrash}.txt`)
		await moveViaDav(fromTrash, `${fromTrash}.txt`)

		await deleteViaDav(`${pastTrash}.txt`, { pastTrash: true })
		await deleteViaDav(`${fromTrash}.txt`)
		const entry = await findTrashbinEntry(`${fromTrash}.txt`)
		expect(entry, 'the renamed file should be in the trash').not.toBeNull()
		await settle()
		expect(await padExists(padIdOfPadUrl(pads[0].padUrl)), 'deleted past the trash, the renamed file should take its pad').toBe(false)
		expect(await padExists(padIdOfPadUrl(pads[1].padUrl)), 'in the trash, the renamed file should keep its pad').toBe(true)

		await purgeTrashbinEntry(entry!)
		await settle()
		expect(await padExists(padIdOfPadUrl(pads[1].padUrl)), 'deleted from the trash, the renamed file should take its pad').toBe(false)
	})

	test('the pads of a folder deleted past the trash go with it, however deep', async () => {
		test.skip(await misnumbersFolderFiles(), misnumbered)
		const folder = uniqueName('gone-past-trash-folder')
		await mkcolViaDav(folder)
		await mkcolViaDav(`${folder}/deep`)
		const pads = [
			await createPadAtPath(`/${folder}/${uniquePadName('one')}`),
			await createPadAtPath(`/${folder}/deep/${uniquePadName('two')}`, 'protected'),
		]

		await deleteViaDav(folder, { pastTrash: true })
		await settle()

		for (const pad of pads) {
			expect(await padExists(padIdOfPadUrl(pad.padUrl)), `${pad.path} should have taken its pad`).toBe(false)
		}
	})

	test('the pads of a folder in the trash stay until it is deleted for good', async () => {
		test.skip(await misnumbersFolderFiles(), misnumbered)
		const folder = uniqueName('gone-trashed-folder')
		await mkcolViaDav(folder)
		const pad = await createPadAtPath(`/${folder}/${uniquePadName('inside')}`)
		const padId = padIdOfPadUrl(pad.padUrl)

		await deleteViaDav(folder)
		const entry = await findTrashbinEntry(folder)
		expect(entry, 'the folder should be in the trash').not.toBeNull()
		await settle()
		expect(await padExists(padId), 'a folder in the trash should keep its pads').toBe(true)

		await purgeTrashbinEntry(entry!)
		await settle()
		expect(await padExists(padId), 'deleted from the trash, the folder should take its pads').toBe(false)
	})

	test('a deleted account takes the pads of its own files', async () => {
		const account = await createAccount(uniqueName('gone-account'))
		try {
			const own = await createPadAtPath(`/${uniquePadName('own')}`, 'protected', account)
			const padId = padIdOfPadUrl(own.padUrl)
			expect(await padExists(padId), 'the pad should exist before the account goes').toBe(true)

			await deleteAccount(account.uid)
			await settle()

			expect(await padExists(padId), 'the account should have taken its pad').toBe(false)
		} finally {
			await deleteAccount(account.uid)
		}
	})
})

test.describe('pads of team folder files deleted for good', () => {
	test.skip(E2E.etherpadApi === null, needsEtherpadApi)

	const team = uniqueName('gone-team')
	const group = uniqueName('gone-team-group')
	let groupMade = false
	let folderId: number | null = null
	/** Files made here, deleted past the trash at the end so their pads go too. */
	const made: string[] = []

	test.beforeAll(async () => {
		test.skip(!(await isAppEnabled('groupfolders')), 'Team folders need the groupfolders app.')
		await createGroup(group)
		groupMade = true
		await addToGroup(E2E.user, group)
		folderId = await createTeamFolder(team, group)
	})

	test.afterAll(async () => {
		for (const path of made.reverse()) {
			await deleteViaDav(path, { pastTrash: true })
		}
		if (folderId !== null) {
			await deleteTeamFolder(folderId)
		}
		if (groupMade) {
			await deleteGroup(group)
		}
	})

	/**
	 * A pad in the new team folder. The first create right after the folder
	 * is made can find no mount yet, so it is tried again for a while.
	 */
	const padInTeam = async (path: string, accessMode = 'public', as: { uid: string, password: string } | null = null) => {
		let lastError: unknown = null
		for (let attempt = 0; attempt < 6; attempt++) {
			try {
				const pad = await createPadAtPath(`/${path}`, accessMode, as)
				made.push(path)
				return pad
			} catch (error) {
				lastError = error
				await new Promise((resolve) => setTimeout(resolve, 1_000 + attempt * 1_000))
			}
		}
		throw lastError
	}

	test('a pad in a team folder goes with its file deleted past the trash', async () => {
		const path = `${team}/${uniquePadName('team-past-trash')}`
		const pad = await padInTeam(path, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)

		await deleteViaDav(path, { pastTrash: true })
		await settle()

		expect(await padExists(padId), 'the job should have deleted the pad').toBe(false)
	})

	/**
	 * A team folder with its own storage - each one, from groupfolders 22 -
	 * keeps its trash under a bare `trash/` there: what leaves it is deleted
	 * for good.
	 */
	test('a folder deleted from a team folder\'s trash takes its pads', async () => {
		test.skip(await misnumbersFolderFiles(), misnumbered)
		const folder = `${team}/${uniqueName('gone-team-sub')}`
		await mkcolViaDav(folder)
		const pad = await padInTeam(`${folder}/${uniquePadName('inside')}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)

		await deleteViaDav(folder)
		const entry = await findTrashbinEntry(folder.split('/').pop()!)
		expect(entry, 'the folder should be in the team folder\'s trash').not.toBeNull()
		await settle()
		expect(await padExists(padId), 'a folder in the trash should keep its pads').toBe(true)

		await purgeTrashbinEntry(entry!)
		await settle()
		expect(await padExists(padId), 'deleted from the trash, the folder should take its pads').toBe(false)
	})

	/**
	 * A folder back from the trash is in Files as before. A team folder
	 * deleted as a whole tells no app about it, so its pads stay - a
	 * restored one's too - and the consistency check lists them.
	 */
	test('a folder restored from the trash is not taken for one gone through it', async () => {
		const other = uniqueName('gone-team-other')
		const otherId = await createTeamFolder(other, group)
		let padId: string | null = null
		try {
			const folder = uniqueName('gone-team-restored')
			await mkcolViaDav(`${other}/${folder}`)
			const pad = await padInTeam(`${other}/${folder}/${uniquePadName('restored')}`)
			made.pop()
			padId = padIdOfPadUrl(pad.padUrl)

			await deleteViaDav(`${other}/${folder}`)
			await restoreFromTrashViaDav(folder)
			const vanishedBefore = await vanishedFiles()
			await deleteTeamFolder(otherId)
			await settle()

			expect(await padExists(padId), 'a pad whose file went unseen should stay').toBe(true)
			expect(await vanishedFiles(), 'the consistency check should count its file as vanished').toBe(vanishedBefore + 1)
		} finally {
			await deleteTeamFolder(otherId)
			// What the app leaves on purpose, taken away by hand - if it did.
			if (padId !== null && await padExists(padId)) {
				await etherpadApiPost('deletePad', { padID: padId })
			}
		}
	})

	/**
	 * The admin takes the pads the app leaves, one listed pad at a time or
	 * all of them: a public one forgotten, its row gone and the pad left in
	 * Etherpad; one deleted on its own; the rest with all, as many as the
	 * check counted. All comes last, and only on a throwaway stack: it takes
	 * every vanished row of the instance.
	 */
	test('the admin forgets a vanished pad, deletes one, then the rest', async () => {
		const other = uniqueName('gone-team-vanished')
		const otherId = await createTeamFolder(other, group)
		const padIds: string[] = []
		try {
			const paths = ['forgotten', 'deleted', 'rest'].map((label) => `${other}/${uniquePadName(label)}`)
			const pads: string[] = []
			for (const [index, path] of paths.entries()) {
				pads.push(padIdOfPadUrl((await padInTeam(path, index === 1 ? 'protected' : 'public')).padUrl))
				made.pop()
			}
			padIds.push(...pads)
			const [forgottenFile, deletedFile] = [await propfindFileId(paths[0]), await propfindFileId(paths[1])]
			await deleteTeamFolder(otherId)
			const vanishedBefore = await vanishedFiles()
			expect(vanishedBefore, 'the team folder deleted as a whole leaves its files vanished').toBeGreaterThanOrEqual(3)

			const refused = await padApiPost('admin/forget-vanished', { fileId: String(deletedFile) })
			expect((refused.body as { forgotten?: boolean }).forgotten, 'a protected pad is not forgotten').toBe(false)
			const forgotten = await padApiPost('admin/forget-vanished', { fileId: String(forgottenFile) })
			expect(forgotten.status, JSON.stringify(forgotten.body)).toBe(200)
			expect((forgotten.body as { forgotten?: boolean }).forgotten).toBe(true)
			const again = await padApiPost('admin/forget-vanished', { fileId: String(forgottenFile) })
			expect((again.body as { forgotten?: boolean }).forgotten, 'no longer vanished, nothing to forget').toBe(false)
			const deleted = await padApiPost('admin/delete-vanished', { fileId: String(deletedFile) })
			expect(deleted.status, JSON.stringify(deleted.body)).toBe(200)
			expect((deleted.body as { marked?: number }).marked).toBe(1)
			await settle()
			expect(await padExists(pads[1]), 'marked on the admin\'s word, the pad should go').toBe(false)
			expect(await padExists(pads[0]), 'forgotten, the pad stays in Etherpad').toBe(true)
			expect(await vanishedFiles(), 'both should be off the list').toBe(vanishedBefore - 2)

			if (!E2E.throwawayStack) {
				return
			}
			const counted = await vanishedFiles()
			const stale = await padApiPost('admin/delete-all-vanished', { expected: String(counted + 1) })
			expect((stale.body as { marked?: number }).marked, 'a count the list no longer has takes nothing').toBe(0)
			const all = await padApiPost('admin/delete-all-vanished', { expected: String(counted) })
			expect(all.status, JSON.stringify(all.body)).toBe(200)
			expect((all.body as { marked?: number }).marked).toBe(counted)
			expect((all.body as { vanished_file_count?: number }).vanished_file_count).toBe(0)
			await settle()
			expect(await padExists(pads[2]), 'marked with all, the pad should go').toBe(false)
			expect(await padExists(pads[0]), 'forgotten, the pad is no vanished file\'s any more').toBe(true)
			expect(await vanishedFiles()).toBe(0)
		} finally {
			await deleteTeamFolder(otherId)
			for (const padId of padIds) {
				if (await padExists(padId)) {
					await etherpadApiPost('deletePad', { padID: padId })
				}
			}
		}
	})

	test('a pad an account made in a team folder stays when the account is deleted', async () => {
		const account = await createAccount(uniqueName('gone-team-account'))
		try {
			await addToGroup(account.uid, group)
			const inTeam = await padInTeam(`${team}/${uniquePadName('team')}`, 'protected', account)
			const own = await createPadAtPath(`/${uniquePadName('own')}`, 'protected', account)

			await deleteAccount(account.uid)
			await settle()

			expect(await padExists(padIdOfPadUrl(own.padUrl)), 'the account\'s own pad should go').toBe(false)
			expect(await padExists(padIdOfPadUrl(inTeam.padUrl)), 'the team folder\'s pad should stay').toBe(true)
			expect(await propfindFileId(inTeam.path), 'and so should its file').toBeGreaterThan(0)
		} finally {
			await deleteAccount(account.uid)
		}
	})
})
