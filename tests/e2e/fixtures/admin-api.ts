/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

/**
 * What only an admin can set up: accounts, groups and team folders, through
 * Nextcloud's own OCS APIs. For the specs about files that leave with an
 * account or a team folder.
 *
 * The login password, not the app password: deleting an account or a team
 * folder needs Nextcloud's password confirmation, which a session opened
 * with a token never holds.
 */
const adminAuthHeader = (): string =>
	`Basic ${Buffer.from(`${E2E.user}:${E2E.password}`).toString('base64')}`

type OcsPayload = { ocs?: { meta?: { statuscode?: number, message?: string }, data?: unknown } }

/**
 * One OCS call as the admin. `tolerate` names OCS or HTTP codes that mean
 * "already so" for a cleanup - an account or folder that is gone already.
 */
const ocs = async (method: string, path: string, form?: Record<string, string>, tolerate: number[] = []): Promise<unknown> => {
	const res = await fetch(`${E2E.baseURL}${path}${path.includes('?') ? '&' : '?'}format=json`, {
		method,
		headers: {
			Authorization: adminAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
		},
		body: form ? new URLSearchParams(form).toString() : undefined,
	})
	const text = await res.text()
	let payload: OcsPayload | null = null
	try {
		payload = text !== '' ? JSON.parse(text) as OcsPayload : null
	} catch {
		throw new Error(`${method} ${path} answered HTTP ${res.status} with a non-JSON body: ${text.slice(0, 200)}`)
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (tolerate.includes(res.status) || tolerate.includes(statusCode)) {
		return null
	}
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`${method} ${path} failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	return payload?.ocs?.data
}

/** Whether an app is enabled on the target. */
export const isAppEnabled = async (app: string): Promise<boolean> => {
	const data = await ocs('GET', '/ocs/v2.php/cloud/apps?filter=enabled') as { apps?: string[] } | null
	return (data?.apps ?? []).includes(app)
}

/** A throwaway account, and a password for it nobody else knows. */
export const createAccount = async (uid: string): Promise<{ uid: string, password: string }> => {
	const password = `E2e-${crypto.randomUUID()}`
	await ocs('POST', '/ocs/v2.php/cloud/users', { userid: uid, password })
	return { uid, password }
}

/** Delete an account, as an admin does in the account management; gone already is fine. */
export const deleteAccount = async (uid: string): Promise<void> => {
	await ocs('DELETE', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`, undefined, [404, 998])
}

export const createGroup = async (gid: string): Promise<void> => {
	await ocs('POST', '/ocs/v2.php/cloud/groups', { groupid: gid })
}

export const deleteGroup = async (gid: string): Promise<void> => {
	await ocs('DELETE', `/ocs/v2.php/cloud/groups/${encodeURIComponent(gid)}`, undefined, [404, 998])
}

export const addToGroup = async (uid: string, gid: string): Promise<void> => {
	await ocs('POST', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/groups`, { groupid: gid })
}

/**
 * A team folder for one group, with every permission. Needs groupfolders;
 * its API has kept these paths across the versions the supported Nextcloud
 * range ships with.
 */
export const createTeamFolder = async (mountPoint: string, gid: string): Promise<number> => {
	const data = await ocs('POST', '/index.php/apps/groupfolders/folders', { mountpoint: mountPoint }) as { id?: number } | null
	const id = Number(data?.id ?? NaN)
	if (!Number.isInteger(id) || id <= 0) {
		throw new Error(`Creating the team folder "${mountPoint}" answered no id.`)
	}
	await ocs('POST', `/index.php/apps/groupfolders/folders/${id}/groups`, { group: gid })
	return id
}

/** Delete a team folder as a whole, as an admin does; gone already is fine. */
export const deleteTeamFolder = async (id: number): Promise<void> => {
	await ocs('DELETE', `/index.php/apps/groupfolders/folders/${id}`, undefined, [404, 998])
}
