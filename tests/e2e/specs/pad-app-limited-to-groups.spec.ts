/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '../fixtures/browser-noise'
import {
	addToGroup,
	createAccount,
	createGroup,
	deleteAccount,
	deleteGroup,
	enableAppForEveryone,
	restrictAppToGroups,
} from '../fixtures/admin-api'
import { deleteViaDav, getAppConfig, padOfFile, propfindFileId, restoreAppConfig, setAppConfig } from '../fixtures/dav'
import { E2E } from '../fixtures/env'
import { uniqueName, uniquePadName } from '../fixtures/nextcloud'

const APP = 'etherpad_nextcloud'
const PROVIDER = 'OCA\\EtherpadNextcloud\\Template\\PadTemplateProvider'

type Account = { uid: string, password: string }

/**
 * The app limited to a group. A user outside it gets no "New pad" entry,
 * and a create sent to Nextcloud's template API anyway provisions no pad.
 * The admin, inside the group, gets both: the requests are the ones the
 * picker sends. Public and external pads are on while it runs, so that a
 * tile is refused for the user and not for its setting. On an instance
 * where the app is already limited, the spec stays out: putting back a
 * limit it did not set is not its business.
 */
test.describe('the app limited to groups', () => {
	const group = uniqueName('pad-users')
	let outsider: Account | null = null
	let restricted = false
	const made: string[] = []
	const settings = { enable_public_pads: 'yes', allow_external_pads: 'yes' }
	const before: Record<string, string> = {}

	const admin = (): Account => ({ uid: E2E.user, password: E2E.password })

	const authOf = (account: Account): string =>
		`Basic ${Buffer.from(`${account.uid}:${account.password}`).toString('base64')}`

	const templateApi = (account: Account, path: string, body?: Record<string, unknown>): Promise<Response> =>
		fetch(`${E2E.baseURL}/ocs/v2.php/apps/files/api/v1/templates${path}?format=json`, {
			method: body === undefined ? 'GET' : 'POST',
			headers: {
				Authorization: authOf(account),
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})

	const offersNewPad = async (account: Account): Promise<boolean> => {
		const res = await templateApi(account, '')
		expect(res.status, `listing the templates as ${account.uid}`).toBe(200)
		const creators = ((await res.json()) as { ocs?: { data?: { app?: string }[] } }).ocs?.data ?? []
		return creators.some((creator) => creator.app === APP)
	}

	/** Whether a name is in the account's trash: a file that was made and removed again. */
	const trashedFor = async (account: Account, name: string): Promise<boolean> => {
		const res = await fetch(`${E2E.baseURL}/remote.php/dav/trashbin/${encodeURIComponent(account.uid)}/trash`, {
			method: 'PROPFIND',
			headers: { Authorization: authOf(account), Depth: '1' },
		})
		expect(res.status, `listing the trash of ${account.uid}`).toBe(207)
		return (await res.text()).includes(name)
	}

	const contentFor = async (account: Account, name: string): Promise<string> => {
		const res = await fetch(`${E2E.baseURL}/remote.php/dav/files/${encodeURIComponent(account.uid)}/${encodeURIComponent(name)}`, {
			headers: { Authorization: authOf(account) },
		})
		expect(res.status, `reading ${name} as ${account.uid}`).toBe(200)
		return res.text()
	}

	const existsFor = async (account: Account, name: string): Promise<boolean> => {
		const res = await fetch(`${E2E.baseURL}/remote.php/dav/files/${encodeURIComponent(account.uid)}/${encodeURIComponent(name)}`, {
			method: 'PROPFIND',
			headers: { Authorization: authOf(account), Depth: '0' },
		})
		if (res.status !== 207 && res.status !== 404) {
			throw new Error(`PROPFIND ${name} as ${account.uid} answered HTTP ${res.status}.`)
		}
		return res.status === 207
	}

	test.beforeAll(async () => {
		const enabled = await getAppConfig('enabled')
		test.skip(enabled !== 'yes', `The app is enabled as ${enabled}, not for everyone.`)
		for (const [key, value] of Object.entries(settings)) {
			before[key] = await getAppConfig(key)
			await setAppConfig(key, value)
		}
		await createGroup(group)
		await addToGroup(E2E.user, group)
		outsider = await createAccount(uniqueName('pad-outsider'))
		// Before the call: Nextcloud 34 installs the app again on the way,
		// which leaves it off until the limit is set, so a call that breaks
		// off still needs undoing.
		restricted = true
		await restrictAppToGroups(APP, [group])
	})

	// Each step on its own: one that fails must not keep the app limited
	// or a setting changed.
	test.afterAll(async () => {
		const failures: unknown[] = []
		const step = async (run: () => Promise<void>): Promise<void> => {
			await run().catch((error: unknown) => {
				failures.push(error)
			})
		}
		if (restricted) {
			await step(() => enableAppForEveryone(APP))
		}
		for (const [key, value] of Object.entries(before)) {
			await step(() => restoreAppConfig(key, value))
		}
		for (const name of made) {
			await step(() => deleteViaDav(name, { pastTrash: true }))
		}
		if (outsider !== null) {
			const uid = outsider.uid
			await step(() => deleteAccount(uid))
		}
		await step(() => deleteGroup(group))
		if (failures.length > 0) {
			throw failures[0]
		}
	})

	test('offers "New pad" only inside the group', async () => {
		expect(await offersNewPad(admin()), 'the admin, inside the group').toBe(true)
		expect(await offersNewPad(outsider!), 'a user outside the group').toBe(false)
	})

	for (const [label, templateType, templateId] of [
		['the blank entry', 'user', ''],
		['the tile of public pads', PROVIDER, `${APP}:pad-public`],
	] as const) {
		test(`creates a pad from ${label} inside the group`, async () => {
			const name = uniquePadName('limited-inside')
			const res = await templateApi(admin(), '/create', { filePath: `/${name}`, templateType, templatePath: templateId })
			made.push(name)

			expect(res.status, 'the create inside the group').toBe(200)
			expect(await padOfFile(await propfindFileId(name))).not.toBe('')
		})
	}

	// Nextcloud makes the file before the app hears of it; it stays as made,
	// with no pad written into it.
	test('provisions no pad from the blank entry for a user outside the group', async () => {
		const name = uniquePadName('limited-outside')
		const res = await templateApi(outsider!, '/create', { filePath: `/${name}`, templateType: 'user', templatePath: '' })

		expect(res.status, 'the create outside the group').toBe(403)
		expect(await contentFor(outsider!, name), 'the file of the refused create').toBe('')
	})

	// A refused tile leaves no file, not even in the trash.
	for (const [label, templateId] of [
		['the tile of public pads', `${APP}:pad-public`],
		['the external tile', `${APP}:pad-external`],
	] as const) {
		test(`creates no file from ${label} for a user outside the group`, async () => {
			const name = uniquePadName('limited-outside')
			const res = await templateApi(outsider!, '/create', { filePath: `/${name}`, templateType: PROVIDER, templatePath: templateId })

			expect(res.status, 'the create outside the group').toBe(403)
			expect(await existsFor(outsider!, name), 'the file of the refused create').toBe(false)
			expect(await trashedFor(outsider!, name), 'the file of the refused create, in the trash').toBe(false)
		})
	}
})
