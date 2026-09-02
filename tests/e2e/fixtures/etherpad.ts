/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

/**
 * The pad server's own API, for the questions only it can answer.
 *
 * Whether a session still grants access is not visible from Nextcloud:
 * sessions live in Etherpad, nothing in our schema mirrors them, and the
 * app learns about them only by asking. A spec that wants to assert a
 * revocation therefore has to ask here.
 *
 * POST, not GET: a query string carries the api key into proxy and access
 * logs, and — with `trace: 'retain-on-failure'` — into the report CI
 * uploads as an artifact. EtherpadClient posts for the same reason. Not a
 * secret-safe channel either way, since a trace can hold the body too;
 * what makes it acceptable is the key, which comes from the checked-in
 * APIKEY.txt of a throwaway stack.
 */
export const etherpadApiPost = async <T>(endpoint: string, form: Record<string, string>): Promise<T> => {
	const api = E2E.etherpadApi
	if (api === null) {
		throw new Error('E2E_ETHERPAD_URL / E2E_ETHERPAD_API_KEY are not configured.')
	}

	const res = await fetch(`${api.url}/api/1.2.15/${endpoint}`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body: new URLSearchParams({ apikey: api.key, ...form }),
	})
	const text = await res.text()
	if (!res.ok) {
		throw new Error(`Etherpad ${endpoint} answered HTTP ${res.status}: ${text.slice(0, 200)}`)
	}

	let payload: { code?: number, message?: string, data?: T } | null = null
	try {
		payload = JSON.parse(text) as typeof payload
	} catch {
		throw new Error(`Etherpad ${endpoint} answered with a non-JSON body: ${text.slice(0, 200)}`)
	}
	if (payload?.code !== 0) {
		throw new Error(`Etherpad ${endpoint} failed: ${payload?.message ?? text.slice(0, 200)}`)
	}
	return payload.data as T
}

/**
 * The Etherpad author a Nextcloud user writes under.
 *
 * `nc:<uid>` is the mapper the app uses, and asking for it twice returns
 * the same id — so this is a lookup, not a side effect, as long as no name
 * is passed along with it.
 *
 * That the mapper is spelled out here rather than read from the app is
 * deliberate. Its shape is load-bearing: Etherpad issues a new author for
 * a mapper it has not seen, and an author nobody can name is an author
 * whose sessions nobody can revoke. A change to it should break a test.
 */
export const authorIdForUid = async (uid: string): Promise<string> => {
	const data = await etherpadApiPost<{ authorID: string }>(
		'createAuthorIfNotExistsFor',
		{ authorMapper: `nc:${uid}` },
	)
	return data.authorID
}

/** The group half of a protected pad's id, read off the pad URL. */
export const groupIdOfPadUrl = (padUrl: string): string => {
	const padId = decodeURIComponent(padUrl.split('/p/').pop() ?? '')
	const groupId = padId.split('$')[0] ?? ''
	if (!groupId.startsWith('g.')) {
		throw new Error(`Not a protected pad URL: ${padUrl}`)
	}
	return groupId
}

/**
 * How many sessions this author still holds on this group that would
 * actually grant access.
 *
 * Live ones only. Etherpad keeps expired sessions until something deletes
 * them, and the app leaves them alone on purpose — counting them would
 * make a successful revoke look like a failed one.
 */
export const liveSessionCount = async (groupId: string, authorId: string): Promise<number> => {
	const data = await etherpadApiPost<Record<string, { authorID: string, validUntil: number } | null>>(
		'listSessionsOfGroup',
		{ groupID: groupId },
	)
	const now = Math.floor(Date.now() / 1000)
	return Object.values(data ?? {})
		.filter((info) => info !== null && info.authorID === authorId && info.validUntil > now)
		.length
}

/** The pad id out of a pad URL, decoded. */
export const padIdOfPadUrl = (padUrl: string): string =>
	decodeURIComponent(padUrl.split('/p/').pop() ?? '')
