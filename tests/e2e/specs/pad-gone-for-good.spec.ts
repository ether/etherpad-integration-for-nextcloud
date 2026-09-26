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
	padApiPost,
	propfindFileId,
	purgeTrashbinEntry,
	restoreFromTrashViaDav,
} from '../fixtures/dav'
import { etherpadApiPost, groupExists, groupIdOfPadUrl, padExists, padIdOfPadUrl } from '../fixtures/etherpad'
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
 * it (docs/deleting-pads.md). A file still in a trash keeps its pad.
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

test.describe('pads of files deleted for good', () => {
	test.skip(E2E.etherpadApi === null, needsEtherpadApi)

	test('a protected pad goes in the same request as its file, deleted past the trash', async () => {
		const name = uniquePadName('gone-past-trash')
		const pad = await createPadAtPath(`/${name}`, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)
		expect(await padExists(padId), 'the pad should exist before the delete').toBe(true)

		await deleteViaDav(name, { pastTrash: true })

		// No settle: the request that deleted the file deleted the pad.
		expect(await findTrashbinEntry(name), 'the file should have skipped the trash').toBeNull()
		expect(await padExists(padId), 'the pad should be gone once the delete answers').toBe(false)
		expect(await groupExists(groupIdOfPadUrl(pad.padUrl)), 'and its group with it').toBe(false)
	})

	test('the pads of a folder deleted past the trash go with it, however deep', async () => {
		const folder = uniqueName('gone-past-trash-folder')
		await mkcolViaDav(folder)
		await mkcolViaDav(`${folder}/deep`)
		const pads = [
			await createPadAtPath(`/${folder}/${uniquePadName('one')}`),
			await createPadAtPath(`/${folder}/deep/${uniquePadName('two')}`, 'protected'),
		]

		await deleteViaDav(folder, { pastTrash: true })

		for (const pad of pads) {
			expect(await padExists(padIdOfPadUrl(pad.padUrl)), `${pad.path} should have taken its pad`).toBe(false)
		}
	})

	test('the pads of a folder in the trash stay until it is deleted for good', async () => {
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

	test('a pad in a team folder goes in the same request as its file, deleted past the trash', async () => {
		const path = `${team}/${uniquePadName('team-past-trash')}`
		const pad = await padInTeam(path, 'protected')
		const padId = padIdOfPadUrl(pad.padUrl)

		await deleteViaDav(path, { pastTrash: true })

		expect(await padExists(padId), 'the pad should be gone once the delete answers').toBe(false)
	})

	/**
	 * A team folder with its own storage - each one, from groupfolders 22 -
	 * keeps its trash under a bare `trash/`, which the sweep's own pass
	 * cannot tell from a folder of that name. The mark from the move to the
	 * trash has to last until the trash lets the folder go.
	 */
	test('a folder deleted from a team folder\'s trash takes its pads', async () => {
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
	 * A restore takes back the mark of the move to the trash. A team folder
	 * deleted as a whole tells no app about it, so its pads stay - a
	 * restored one too, which a mark left behind would have let the sweep
	 * delete.
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
			await deleteTeamFolder(otherId)
			await settle()

			expect(await padExists(padId), 'a pad whose file went unseen should stay').toBe(true)
		} finally {
			await deleteTeamFolder(otherId)
			// What the app leaves on purpose, taken away by hand - if it did.
			if (padId !== null && await padExists(padId)) {
				await etherpadApiPost('deletePad', { padID: padId })
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
