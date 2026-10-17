/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 *
 * The pads of test-upgrade.sh: made through the API of the release it
 * upgrades from, and checked against the app it upgrades to. Each phase
 * reads and writes its files in the directory it is given:
 *
 *   node upgrade-fixtures.mjs seed|offline|migrated|verify <dir>
 */
import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { parseEnv } from 'node:util'

/**
 * @typedef {{ label: string, name: string, mode: string, padId: string, id: number, marker: string, content?: string, keptInTrash?: boolean }} Pad
 * @typedef {{ pads: Record<string, Pad>, publicShareToken: string }} State
 * @typedef {Record<string, unknown> & { file_id: number | string, state: string, file_cached: boolean }} Row
 * @typedef {{ id: string, class: string, argument: string }} Job
 * @typedef {{ bindings: Row[], migrations: string[], jobs: Job[], settings: Record<string, string>, indexes: string[] }} Snapshot
 * @typedef {{ method?: string, form?: Record<string, string>, body?: string, headers?: Record<string, string>, secondary?: boolean, anonymous?: boolean, status?: number | number[] }} RequestOptions
 */

const [phase, dir] = process.argv.slice(2)
/**
 * @param {string} name
 * @returns {any}
 */
const readJson = (name) => JSON.parse(readFileSync(join(dir, name), 'utf8'))

const envFile = process.env.E2E_ENV_FILE
assert.ok(envFile, 'E2E_ENV_FILE names the env file of the stack')
// As in playwright.config.ts: a named env file wins over the shell.
Object.assign(process.env, parseEnv(readFileSync(envFile, 'utf8')))
const env = process.env
const base = env.E2E_BASE_URL
assert.equal(base, 'https://nc.pad.test', 'The throwaway Docker stack is the only target')
/** @param {string} name */
const required = (name) => {
	const value = env[name]
	assert.ok(value, `${name} is not set`)
	return value
}

/** @param {boolean} secondary */
const auth = (secondary) => 'Basic ' + Buffer.from(secondary
	? `${env.E2E_USER2}:${env.E2E_USER2_APP_PASSWORD}`
	: `${env.E2E_USER}:${env.E2E_APP_PASSWORD}`).toString('base64')

/**
 * @param {string} path
 * @param {RequestOptions} [options]
 * @returns {Promise<{ data: any, res: Response }>}
 */
async function request(path, { method = 'GET', form, body, headers = {}, secondary = false, anonymous = false, status = 200 } = {}) {
	const res = await fetch(path.startsWith('https:') ? path : base + path, {
		method,
		headers: {
			...(!anonymous && { Authorization: auth(secondary), 'OCS-APIRequest': 'true' }),
			...(form && { 'Content-Type': 'application/x-www-form-urlencoded' }),
			...headers,
		},
		body: form ? new URLSearchParams(form) : body,
		signal: AbortSignal.timeout(45_000),
	})
	const text = await res.text()
	const expected = Array.isArray(status) ? status : [status]
	assert.ok(expected.includes(res.status), `${method} ${path}: HTTP ${res.status}: ${text.slice(0, 500)}`)
	/** @type {any} */
	let data
	try {
		data = JSON.parse(text)
	} catch {
		data = text
	}
	return { data, res }
}

/**
 * @param {string} path
 * @param {Record<string, string>} [form]
 * @param {RequestOptions} [options]
 */
const api = (path, form = {}, options = {}) => request(`/index.php/apps/etherpad_nextcloud/api/v1/${path}`, { method: 'POST', form, ...options })
const settle = async () => (await api('admin/settle-pending')).data
/** @param {string} name */
const dav = (name) => `/remote.php/dav/files/${env.E2E_USER}/${encodeURIComponent(name)}`
/** @param {Pad} p */
const fileText = async (p) => (await request(dav(p.name))).data

/**
 * @param {string} method
 * @param {Record<string, string>} [form]
 * @returns {Promise<any>}
 */
const etherpad = async (method, form = {}) => {
	const { data } = await request(`${env.E2E_ETHERPAD_URL}/api/1.2.15/${method}`, {
		method: 'POST', form: { apikey: required('E2E_ETHERPAD_API_KEY'), ...form }, anonymous: true,
	})
	assert.equal(data.code, 0, `${method}: ${JSON.stringify(data)}`)
	return data.data
}
/** @param {string} padId */
const etherpadHas = async (padId) => (await etherpad('listAllPads')).padIDs.includes(padId)
/** @param {string} padUrl */
const padIdOf = (padUrl) => decodeURIComponent(new URL(padUrl).pathname.slice('/p/'.length))
/** @param {string} text */
const escapeHtml = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
/**
 * @param {Pad} p
 * @param {string} text
 */
const write = (p, text) => etherpad('setHTML', { padID: p.padId, html: `<html><body><p><strong>${escapeHtml(text)}</strong></p></body></html>` })

/** @param {string} name */
const fileId = async (name) => {
	const { data } = await request(dav(name), {
		method: 'PROPFIND', headers: { Depth: '0' }, status: 207,
		body: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const id = Number(data.match(/<oc:fileid>(\d+)<\/oc:fileid>/)?.[1])
	assert.ok(id > 0, `No file id for ${name}`)
	return id
}

const trashRoot = `/remote.php/dav/trashbin/${env.E2E_USER}/`
/** @param {string} name */
const trashEntry = async (name) => {
	const { data } = await request(trashRoot + 'trash', {
		method: 'PROPFIND', headers: { Depth: '1' }, status: 207,
		body: '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:trashbin-filename/></d:prop></d:propfind>',
	})
	const entry = /** @type {string} */ (data).split('<d:response>').find((chunk) => chunk.includes(`<nc:trashbin-filename>${name}</nc:trashbin-filename>`))
	const href = entry?.match(/<d:href>([^<]+)<\/d:href>/)?.[1]
	assert.ok(href, `No trash entry ${name}`)
	return href
}
/** @param {Pad} p */
const trash = (p) => request(dav(p.name), { method: 'DELETE', status: 204 })
/** @param {Pad} p */
const deleteFromTrash = async (p) => request(await trashEntry(p.name), { method: 'DELETE', status: 204 })
/** @param {Pad} p */
const restore = async (p) => request(await trashEntry(p.name), {
	method: 'MOVE', status: [201, 204],
	headers: { Destination: base + trashRoot + 'restore/' + encodeURIComponent(p.name) },
})

/**
 * @param {Pad} p
 * @param {boolean} [secondary]
 * @returns {Promise<string>} the pad the open gets
 */
const open = async (p, secondary = false) => {
	const { data, res } = await api('pads/open-by-id', { fileId: String(p.id) }, { secondary })
	if (p.mode === 'protected') {
		assert.match(res.headers.get('set-cookie') ?? '', /sessionID=/, `${p.label} opened without a session`)
	}
	return padIdOf(data.pad_url)
}

/**
 * Each label is a state a user can leave a pad in. The "offline" ones are
 * made while Etherpad is down, where 1.1.0-beta.1 left a deletion waiting.
 */
const PADS = [
	['public', 'public'],
	['protected', 'protected'],
	['trashed-public', 'public'],
	['trashed-protected', 'protected'],
	['trashed-offline', 'protected'],
	['deleted-offline', 'protected'],
	['trash-call-offline', 'protected'],
]

/** A value the migration sets to the time it runs: taken as it is found. */
const ANY = Symbol('any')

/**
 * What each migration changes in the rows, settings and index names the
 * snapshot holds, applied to the snapshot from before it ran. The rest of
 * those has to stay as it was. A migration that runs needs an entry, empty
 * if it changes none of them.
 *
 * @type {Record<string, (expected: Snapshot) => void>}
 */
const MIGRATIONS = {
	'000005Date20260928120000': (expected) => {
		// The trash keeps a pad: a deletion that waited while its file is
		// still in the file cache, in a trash or in Files, is active again.
		// The migration also dates a restore that could not finish and a
		// deletion without a date; no release left those, so none is made.
		for (const row of expected.bindings) {
			if (row.state === 'pending_delete' && row.file_cached) {
				Object.assign(row, { state: 'active', deleted_at: null, updated_at: ANY })
			}
		}
		// As AppConfigService::takeOverDeleteOnTrash() reads them: an empty
		// value is none.
		const { settings } = expected
		if ((settings.delete_on_trash ?? '') !== '') {
			if ((settings.delete_pad_with_file ?? '') === '') {
				settings.delete_pad_with_file = settings.delete_on_trash
			}
			delete settings.delete_on_trash
		}
		delete settings.test_fault
		expected.indexes = [...expected.indexes.filter((name) => name !== 'ep_bind_state_idx'), 'ep_bind_state_mode_idx']
	},
}

/** @param {string} root */
const readTarget = (root) => {
	const info = readFileSync(join(root, 'appinfo/info.xml'), 'utf8')
	return {
		version: info.match(/<version>([^<]+)<\/version>/)?.[1] ?? '',
		migrations: readdirSync(join(root, 'lib/Migration'))
			.filter((name) => /^Version\w+\.php$/.test(name))
			.map((name) => name.slice('Version'.length, -'.php'.length))
			.sort(),
		jobs: [...info.matchAll(/<job>([^<]+)<\/job>/g)].map((match) => match[1]),
		/** @param {string} name */
		hasClass: (name) => existsSync(join(root, 'lib', ...name.replace(/^OCA\\EtherpadNextcloud\\/, '').split('\\')) + '.php'),
	}
}

/**
 * `expected` with each ANY replaced by what `actual` has there.
 *
 * @param {Row[]} expected
 * @param {Row[]} actual
 * @returns {Row[]}
 */
const filledIn = (expected, actual) => expected.map((row, i) => /** @type {Row} */ (Object.fromEntries(
	Object.entries(row).map(([key, value]) => [key, value === ANY ? actual[i]?.[key] : value]),
)))

if (phase === 'seed') {
	/** @type {Record<string, Pad>} */
	const pads = {}
	for (const [label, mode] of PADS) {
		const name = `upgrade-${label}.pad`
		const { data } = await api('pads', { file: '/' + name, accessMode: mode })
		/** @type {Pad} */
		const p = { label, name, mode, padId: padIdOf(data.pad_url), id: await fileId(name), marker: `Before the upgrade, ${label}: Zürich ✓ & <saved>` }
		await write(p, p.marker)
		await api(`pads/sync/${p.id}`)
		const content = await fileText(p)
		assert.ok(content.includes(p.marker), `${label} did not sync`)
		p.content = content
		pads[label] = p
	}
	// Written after the last sync: a trash with Etherpad down cannot save
	// it into the file, so only a pad the upgrade kept has it.
	const offline = pads['trashed-offline']
	offline.marker += ', written after the last sync'
	await write(offline, offline.marker)

	assert.equal(await open(pads.protected), pads.protected.padId)
	for (const label of ['trashed-public', 'trashed-protected']) {
		await trash(pads[label])
		// Up to 1.1.0-beta.1 the trash deleted the pad, and a restore makes
		// a new one; later releases keep it.
		pads[label].keptInTrash = await etherpadHas(pads[label].padId)
	}
	/** @param {Record<string, string>} form */
	const share = async (form) => (await request('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', { method: 'POST', form })).data.ocs.data
	const publicShare = await share({ path: '/' + pads.public.name, shareType: '3', permissions: '1' })
	await share({ path: '/' + pads.protected.name, shareType: '0', shareWith: required('E2E_USER2'), permissions: '3' })
	assert.equal(await open(pads.protected, true), pads.protected.padId)
	writeFileSync(join(dir, 'state.json'), JSON.stringify({ pads, publicShareToken: publicShare.token }, null, '\t'))
} else if (phase === 'offline') {
	/** @type {State} */
	const state = readJson('state.json')
	const { pads } = state
	await trash(pads['trashed-offline'])
	await trash(pads['deleted-offline'])
	await deleteFromTrash(pads['deleted-offline'])
	// 1.1.0-beta.1's call that deleted a file's pad and left the file where
	// it was; later releases have none, and the pad stays as it is.
	const call = pads['trash-call-offline']
	const { data, res } = await api('pads/trash', { file: '/' + call.name }, { status: [200, 404, 405] })
	if (res.status === 200) {
		assert.equal(data.delete_pending, true, JSON.stringify(data))
	}
	writeFileSync(join(dir, 'state.json'), JSON.stringify(state, null, '\t'))
} else if (phase === 'migrated') {
	/** @type {State} */
	const { pads } = readJson('state.json')
	/** @type {Snapshot} */
	const before = readJson('before.json')
	/** @type {Snapshot} */
	const after = readJson('after.json')
	const target = readTarget(required('UPGRADE_TARGET'))
	/** @param {Row} row */
	const labelOf = (row) => Object.values(pads).find((p) => p.id === Number(row.file_id))?.label ?? `file ${row.file_id}`

	const ran = target.migrations.filter((version) => !before.migrations.includes(version))
	console.log(`Migrations that ran: ${ran.join(', ') || 'none'}`)
	assert.deepEqual(after.migrations, target.migrations, 'migrations')

	const expected = structuredClone(before)
	for (const version of ran) {
		assert.ok(version in MIGRATIONS, `${version} ran, and MIGRATIONS says nothing of what it changes`)
		MIGRATIONS[version](expected)
	}
	expected.settings.installed_version = target.version
	expected.indexes.sort()

	assert.deepEqual(after.bindings, filledIn(expected.bindings, after.bindings), 'rows')
	assert.deepEqual(after.settings, expected.settings, 'settings')
	assert.deepEqual(after.indexes, expected.indexes, 'indexes of the rows')
	// A job whose class is gone does not outlive the upgrade: cron would
	// drop it with a warning each.
	const added = after.jobs.filter((job) => !before.jobs.some((old) => old.id === job.id))
	assert.deepEqual(added.map((job) => job.class).sort(), target.jobs.filter((name) => !before.jobs.some((job) => job.class === name)).sort(), 'jobs added')
	assert.deepEqual(after.jobs.filter((job) => !added.includes(job)), before.jobs.filter((job) => target.hasClass(job.class)), 'jobs kept')

	for (const row of after.bindings) {
		const was = before.bindings.find((old) => old.file_id === row.file_id)
		if (was?.state !== row.state) {
			console.log(`  ${labelOf(row)}: ${was?.state} -> ${row.state}`)
		}
	}
	for (const job of before.jobs.filter((old) => !after.jobs.some((now) => now.id === old.id))) {
		console.log(`  job removed: ${job.class}`)
	}
	for (const job of added) {
		console.log(`  job added: ${job.class}`)
	}

	// The key stays, its value hidden from `occ config:list`.
	const config = readJson('config.json')
	assert.equal(config.apps.etherpad_nextcloud.etherpad_api_key, '***REMOVED SENSITIVE VALUE***')
} else if (phase === 'verify') {
	/** @type {State} */
	const { pads, publicShareToken } = readJson('state.json')

	for (const label of ['public', 'protected']) {
		const p = pads[label]
		assert.equal(await fileId(p.name), p.id, `${label} has another file id`)
		assert.equal(await fileText(p), p.content, `the upgrade changed the file of ${label}`)
		assert.equal(await open(p), p.padId, `${label} opened another pad`)
		await api(`pads/sync/${p.id}`)
		assert.ok((await fileText(p)).includes(p.marker), `${label} lost its text`)
	}
	assert.equal(await open(pads.protected, true), pads.protected.padId, 'the share opened another pad')
	// A read share: its visitor gets the pad's read-only address in Etherpad.
	const { data: shared } = await request(`/index.php/apps/etherpad_nextcloud/api/v1/public/open/${publicShareToken}`, { anonymous: true })
	assert.equal(shared.is_readonly_view, false, `the link opened the app's read-only view, not the pad: ${JSON.stringify(shared)}`)
	const { readOnlyID } = await etherpad('getReadOnlyID', { padID: pads.public.padId })
	assert.equal(padIdOf(shared.url), readOnlyID, `the link opened another pad: ${JSON.stringify(shared)}`)

	const call = pads['trash-call-offline']
	assert.equal(await open(call), call.padId, 'trash-call-offline opened another pad')
	await api(`pads/sync/${call.id}`)
	assert.ok((await fileText(call)).includes(call.marker), 'trash-call-offline lost its text')

	const gone = pads['deleted-offline']
	const settled = await settle()
	assert.equal(settled.settled, 1, JSON.stringify(settled))
	assert.equal(settled.pending_delete_count, 0, JSON.stringify(settled))
	assert.ok(!(await etherpadHas(gone.padId)), 'deleted-offline kept its pad')

	const offline = pads['trashed-offline']
	assert.ok(await etherpadHas(offline.padId), 'trashed-offline lost its pad in the trash')
	await restore(offline)
	assert.equal(await fileId(offline.name), offline.id, 'trashed-offline came back with another file id')
	assert.equal(await open(offline), offline.padId, 'trashed-offline opened another pad')
	await api(`pads/sync/${offline.id}`)
	assert.ok((await fileText(offline)).includes(offline.marker), 'trashed-offline lost what was written after its last sync')

	for (const label of ['trashed-public', 'trashed-protected']) {
		const p = pads[label]
		await restore(p)
		const padId = await open(p)
		if (p.keptInTrash) {
			assert.equal(padId, p.padId, `${label} opened another pad`)
		} else {
			assert.notEqual(padId, p.padId, `${label} opened the pad its trash deleted`)
		}
		assert.ok((await etherpad('getText', { padID: padId })).text.includes(p.marker), `${label} lost its text`)
		assert.match((await etherpad('getHTML', { padID: padId })).html, /<(?:strong|b)>/, `${label} lost its formatting`)
		p.padId = padId
	}

	// Deleted on this version: the trash keeps the pad, and a deletion for
	// good takes it.
	const deleted = pads['trashed-public']
	await trash(deleted)
	assert.ok(await etherpadHas(deleted.padId), 'the trash took the pad of trashed-public')
	await deleteFromTrash(deleted)
	const settledAgain = await settle()
	assert.equal(settledAgain.settled, 1, JSON.stringify(settledAgain))
	assert.ok(!(await etherpadHas(deleted.padId)), 'trashed-public kept its pad after its file was deleted for good')
	assert.equal((await settle()).settled, 0, 'a check with nothing waiting deleted a pad')
} else {
	throw new Error(`Unknown phase ${phase}: seed, offline, migrated or verify`)
}
