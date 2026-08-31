/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

export const basicAuthHeader = (): string => {
	const auth = Buffer.from(`${E2E.user}:${E2E.appPassword}`).toString('base64')
	return `Basic ${auth}`
}

const parseJsonResponse = async (res: Response): Promise<unknown> => {
	const text = await res.text()
	try {
		return text !== '' ? JSON.parse(text) : null
	} catch (error) {
		throw new Error(`Expected JSON response but got HTTP ${res.status}: ${text.slice(0, 200)}`)
	}
}

const davUrl = (relativePath: string): string => {
	const path = relativePath.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	return `${E2E.baseURL}/remote.php/dav/files/${encodeURIComponent(E2E.user)}/${path}`
}

/**
 * The trash helpers run in globalTeardown, which Playwright does not put a
 * timeout on. Without a bound, a wedged instance leaves the runner hanging
 * after the report is already written until CI kills the job — housekeeping
 * deciding whether the run passed, which is exactly what it must not do.
 */
const TRASH_REQUEST_TIMEOUT_MS = 30_000

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

/** Decode the XML entities WebDAV property text can carry (not URL-encoding). */
const xmlUnescape = (value: string): string => value
	.replace(/&lt;/g, '<')
	.replace(/&gt;/g, '>')
	.replace(/&quot;/g, '"')
	.replace(/&apos;/g, "'")
	.replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
	.replace(/&amp;/g, '&')

/**
 * Run `request()` until it stops returning a transient WebDAV state.
 * A freshly created/just-closed .pad is briefly held by NC's sync write
 * while Etherpad mirrors content back, and PROPFIND just after a UI
 * create can race the filecache; both surfaces as one of the codes in
 * `retryOn`. Up to `maxAttempts` tries with linear backoff.
 *
 * `accept` returns true for terminal success (typically 2xx + 207).
 */
const withDavRetry = async (
	request: () => Promise<Response>,
	options: { retryOn: number[], accept: (status: number) => boolean, maxAttempts?: number, label: string },
): Promise<Response> => {
	const maxAttempts = options.maxAttempts ?? 5
	let lastStatus = 0
	for (let attempt = 0; attempt < maxAttempts; attempt++) {
		const res = await request()
		if (options.accept(res.status)) {
			return res
		}
		lastStatus = res.status
		if (!options.retryOn.includes(res.status)) {
			throw new Error(`WebDAV ${options.label} failed with HTTP ${res.status}`)
		}
		await sleep(500 + attempt * 500)
	}
	throw new Error(`WebDAV ${options.label} still failing after ${maxAttempts} attempts (last HTTP ${lastStatus})`)
}

/**
 * Delete a file or folder through WebDAV. Used for teardown so browser
 * specs do not leave pads behind on a shared target instance.
 */
export const deleteViaDav = async (relativePath: string): Promise<void> => {
	const path = relativePath.replace(/^\/+/, '')
	// 404 is a successful no-op for cleanup (file already gone). 423
	// (Locked) is briefly hit when a pad was just closed and Etherpad's
	// sync write still holds the file lock — retry until it clears.
	await withDavRetry(
		() => fetch(davUrl(path), { method: 'DELETE', headers: { Authorization: basicAuthHeader() } }),
		{
			retryOn: [423],
			accept: (status) => status < 300 || status === 404,
			label: `DELETE ${path}`,
		},
	)
}

export const putFileViaDav = async (relativePath: string, content: string): Promise<void> => {
	const path = relativePath.replace(/^\/+/, '')
	const res = await fetch(davUrl(path), {
		method: 'PUT',
		headers: {
			Authorization: basicAuthHeader(),
			'Content-Type': 'text/plain; charset=UTF-8',
		},
		body: content,
	})
	if (!res.ok && res.status !== 201 && res.status !== 204) {
		throw new Error(`WebDAV PUT ${path} failed with HTTP ${res.status}`)
	}
}

/** Create a collection (folder) via WebDAV MKCOL. 405 (already exists) is a no-op. */
export const mkcolViaDav = async (relativePath: string): Promise<void> => {
	const path = relativePath.replace(/^\/+/, '')
	const res = await fetch(davUrl(path), { method: 'MKCOL', headers: { Authorization: basicAuthHeader() } })
	if (!res.ok && res.status !== 201 && res.status !== 405) {
		throw new Error(`WebDAV MKCOL ${path} failed with HTTP ${res.status}`)
	}
}

/**
 * Shared MOVE/COPY driver with a bounded lock retry.
 *
 * A pad that was just created/closed may still be locked by the sync
 * write. NC surfaces that lock inconsistently as either 423 (Locked) or
 * an uncaught LockedException — and in the 500 case the response body is
 * NC's generic HTML error page with no "locked" marker (confirmed in the
 * server log: `OCP\Lock\LockedException` rendered as a plain 500). So the
 * lock is *not* distinguishable from the response alone; we retry both
 * 423 and 500 with backoff.
 *
 * This does not weaken what the move/rename specs verify. They assert the
 * binding survives a move — which would regress as a *failed reopen
 * afterwards*, not as a MOVE returning 500 (MOVE/COPY status is NC-core
 * plumbing, not our plugin). A genuinely broken, deterministic 500 here
 * would also exhaust the bounded retries and still fail loudly.
 */
const davMoveOrCopy = async (method: 'MOVE' | 'COPY', srcPath: string, destPath: string): Promise<void> => {
	await withDavRetry(
		() => fetch(davUrl(srcPath), {
			method,
			headers: { Authorization: basicAuthHeader(), Destination: davUrl(destPath), Overwrite: 'F' },
		}),
		{ retryOn: [423, 500], accept: (status) => status < 300, label: `${method} ${srcPath} -> ${destPath}` },
	)
}

/** Move/rename a file via WebDAV MOVE. The file id is preserved across a move. */
export const moveViaDav = async (srcRelativePath: string, destRelativePath: string): Promise<void> => {
	await davMoveOrCopy('MOVE', srcRelativePath.replace(/^\/+/, ''), destRelativePath.replace(/^\/+/, ''))
}

/** Read a file's raw bytes via WebDAV GET. Retries on the post-create lock race. */
export const getFileViaDav = async (relativePath: string): Promise<string> => {
	const path = relativePath.replace(/^\/+/, '')
	const res = await withDavRetry(
		() => fetch(davUrl(path), { method: 'GET', headers: { Authorization: basicAuthHeader() } }),
		{ retryOn: [423, 404], accept: (status) => status >= 200 && status < 300, label: `GET ${path}` },
	)
	return res.text()
}

/**
 * POST to one of the plugin's authenticated `/api/v1/pads/...` endpoints
 * using the app password (same BasicAuth surface the integration bash
 * specs use). Returns the parsed JSON body plus the HTTP status.
 */
export const padApiPost = async (endpoint: string): Promise<{ status: number, body: unknown }> => {
	const url = `${E2E.baseURL}/index.php/apps/etherpad_nextcloud/api/v1/${endpoint.replace(/^\/+/, '')}`
	const res = await fetch(url, {
		method: 'POST',
		headers: {
			Authorization: basicAuthHeader(),
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
		},
	})
	const text = await res.text()
	let body: unknown = null
	try {
		body = text !== '' ? JSON.parse(text) : null
	} catch {
		body = text
	}
	return { status: res.status, body }
}

/** Return the display name NC exposes for the primary E2E account. */
export const getCurrentUserDisplayName = async (): Promise<string> => {
	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/cloud/user?format=json`, {
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	const payload = await parseJsonResponse(res) as {
		ocs?: { meta?: { statuscode?: number, message?: string }, data?: Record<string, unknown> }
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`OCS current-user lookup failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	const data = payload?.ocs?.data || {}
	const displayName = String(data['display-name'] || data.displayname || data.displayName || '').trim()
	return displayName !== '' ? displayName : E2E.user
}

/**
 * Server-side WebDAV COPY. The copy receives a new file id, so any
 * existing binding row stays attached to the source — the destination
 * shows up as an orphaned .pad in the viewer flow. Used by the orphan-
 * recovery spec to set up that exact state without poking the DB.
 */
export const copyViaDav = async (srcRelativePath: string, destRelativePath: string): Promise<void> => {
	await davMoveOrCopy('COPY', srcRelativePath.replace(/^\/+/, ''), destRelativePath.replace(/^\/+/, ''))
}

const trashbinUrl = (subpath: string = ''): string => {
	const tail = subpath.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	const suffix = tail === '' ? '' : `/${tail}`
	return `${E2E.baseURL}/remote.php/dav/trashbin/${encodeURIComponent(E2E.user)}${suffix}`
}

/**
 * List trashbin entries that originally carried `originalFileName`. NC
 * renames trashed files to `<name>.d<timestamp>` server-side, so the
 * trashed entry's `name` does not equal the file's pre-trash name; the
 * original name is exposed via the `nc:trashbin-filename` property.
 *
 * Returns the trashbin path (relative to trashbin root) so callers can
 * issue a MOVE for restore.
 */
export const findTrashbinEntry = async (originalFileName: string): Promise<string | null> => {
	const match = (await listTrashbinEntries())
		.find((candidate) => candidate.originalName === originalFileName)
	return match ? match.entry : null
}

/**
 * Every entry currently in the trash, as `{ entry, originalName }` — the
 * entry being the path below the trash root, which is what a MOVE or
 * DELETE needs. Used by `findTrashbinEntry` and by the global teardown's
 * sweep.
 */
export const listTrashbinEntries = async (): Promise<{ entry: string, originalName: string }[]> => {
	const body = '<?xml version="1.0"?>\n'
		+ '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns">\n'
		+ '  <d:prop><nc:trashbin-filename/><oc:trashbin-original-filename/></d:prop>\n'
		+ '</d:propfind>'
	const res = await withDavRetry(
		() => fetch(trashbinUrl('trash'), {
			method: 'PROPFIND',
			headers: {
				Authorization: basicAuthHeader(),
				Depth: '1',
				'Content-Type': 'application/xml; charset=UTF-8',
			},
			body,
			signal: AbortSignal.timeout(TRASH_REQUEST_TIMEOUT_MS),
		}),
		{ retryOn: [423], accept: (status) => status < 300, label: 'PROPFIND trashbin' },
	)
	const xml = await res.text()
	// Parse minimally: walk each <d:response>, extract href + the
	// original-filename property.
	const prefix = `/remote.php/dav/trashbin/${E2E.user}/`
	const entries: { entry: string, originalName: string }[] = []
	for (const chunk of xml.split(/<d:response[\s>]/i).slice(1)) {
		const hrefMatch = chunk.match(/<d:href[^>]*>([^<]+)<\/d:href>/i)
		const originalMatch = chunk.match(/<(?:oc:trashbin-original-filename|nc:trashbin-filename)[^>]*>([^<]+)<\/(?:oc:trashbin-original-filename|nc:trashbin-filename)>/i)
		if (!hrefMatch || !originalMatch) {
			continue
		}
		// One entry with a stray % sequence must not take the whole listing
		// down with it — that is the failure this listing exists to survive.
		let href: string
		try {
			href = decodeURIComponent(hrefMatch[1].trim())
		} catch {
			continue
		}
		if (!href.startsWith(prefix)) {
			continue
		}
		entries.push({
			entry: href.slice(prefix.length).replace(/\/+$/, ''),
			// The property value is XML text (XML-escaped), not URL-encoded,
			// so unescape XML entities — not decodeURIComponent, which would
			// throw on a literal '%' and leave entities like &amp; intact.
			originalName: xmlUnescape(originalMatch[1].trim()),
		})
	}
	return entries
}

/**
 * Permanently remove one trashed entry, the way the trash view's "Delete
 * permanently" action does. `entry` is a path as returned by
 * `listTrashbinEntries`.
 */
export const purgeTrashbinEntry = async (entry: string): Promise<void> => {
	await withDavRetry(
		() => fetch(trashbinUrl(entry), {
			method: 'DELETE',
			headers: { Authorization: basicAuthHeader() },
			signal: AbortSignal.timeout(TRASH_REQUEST_TIMEOUT_MS),
		}),
		// 404 means it is already gone — same end state.
		{ retryOn: [423], accept: (status) => status < 300 || status === 404, label: `DELETE ${entry}` },
	)
}

/**
 * Restore a trashed file via WebDAV MOVE — the Files-app trash UI does
 * the same thing under the hood, but driving it through the API instead
 * of the virtualized trash row list keeps the spec stable when NC's
 * trash view changes its DOM shape across releases.
 */
export const restoreFromTrashViaDav = async (originalFileName: string): Promise<void> => {
	const entry = await findTrashbinEntry(originalFileName)
	if (entry === null) {
		throw new Error(`No trashbin entry found for "${originalFileName}".`)
	}
	await withDavRetry(
		() => fetch(trashbinUrl(entry), {
			method: 'MOVE',
			headers: {
				Authorization: basicAuthHeader(),
				Destination: trashbinUrl('restore/' + originalFileName),
			},
			signal: AbortSignal.timeout(TRASH_REQUEST_TIMEOUT_MS),
		}),
		{ retryOn: [423], accept: (status) => status < 300, label: `MOVE restore ${originalFileName}` },
	)
}

/**
 * The paths currently shared with the secondary account, read through the
 * OCS share API as that user.
 *
 * The user-share spec calls its own docblock's bluff with this: "NC's
 * share API is the authoritative boundary". Asserting a revoke through
 * the Files UI depends on when the view finishes rendering, and a list
 * that has not rendered yet looks exactly like one the row is gone from.
 */
export const sharedWithMePaths = async (): Promise<string[]> => {
	const user = E2E.secondaryUser
	const password = E2E.secondaryAppPassword
	if (user === null || password === null) {
		throw new Error('sharedWithMePaths needs E2E_USER2 and E2E_USER2_APP_PASSWORD.')
	}
	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&shared_with_me=true`, {
		headers: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	const payload = await parseJsonResponse(res) as {
		ocs?: { meta?: { statuscode?: number, message?: string }, data?: { path?: string }[] }
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`OCS shared-with-me lookup failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	return (payload?.ocs?.data || []).map((share) => String(share.path || ''))
}

/**
 * Create a pad at an exact path through the app's own API, and return the
 * Etherpad address it was given.
 *
 * The address is what makes a test able to say *which* pad was opened.
 * Asserting that a viewer appeared is not enough: the bug this guards
 * against opened a viewer just as happily, for the wrong document.
 */
export const createPadAtPath = async (absolutePath: string, accessMode = 'public'): Promise<{ path: string, padUrl: string }> => {
	const res = await fetch(`${E2E.baseURL}/index.php/apps/etherpad_nextcloud/api/v1/pads`, {
		method: 'POST',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			Accept: 'application/json',
		},
		body: new URLSearchParams({ file: absolutePath, accessMode }),
	})
	// Status first, body second: an HTML error page would make
	// parseJsonResponse throw its own generic message, and the path that
	// failed — the one detail that says which of two confusable names it
	// was — would never reach the report.
	const text = await res.text()
	let payload: { file?: string, pad_url?: string, message?: string } | null = null
	try {
		payload = text !== '' ? JSON.parse(text) : null
	} catch {
		payload = null
	}
	if (!res.ok) {
		const detail = payload?.message || text.slice(0, 200) || 'no response body'
		throw new Error(`Pad create failed for "${absolutePath}" with HTTP ${res.status}: ${detail}`)
	}
	if (payload === null) {
		throw new Error(`Pad create for "${absolutePath}" answered HTTP ${res.status} with a non-JSON body: ${text.slice(0, 200)}`)
	}
	const path = String(payload?.file || '')
	const padUrl = String(payload?.pad_url || '')
	if (path === '' || padUrl === '') {
		throw new Error(`Pad create response for "${absolutePath}" carried no path and pad URL.`)
	}
	return { path, padUrl }
}

/**
 * PROPFIND for the file's fileid. Used by specs that need the numeric id
 * for cross-user / API permission checks. Throws if the file is missing
 * or the fileid prop cannot be parsed.
 */
export const propfindFileId = async (relativePath: string): Promise<number> => {
	const path = relativePath.replace(/^\/+/, '')
	const body = '<?xml version="1.0"?>\n'
		+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">\n'
		+ '  <d:prop><oc:fileid/></d:prop>\n'
		+ '</d:propfind>'

	// PROPFIND right after a UI-driven create sometimes hits 404 because
	// NC's filecache propagation lags the create response by a few ms;
	// retry until the file is visible to DAV.
	const res = await withDavRetry(
		() => fetch(davUrl(path), {
			method: 'PROPFIND',
			headers: {
				Authorization: basicAuthHeader(),
				Depth: '0',
				'Content-Type': 'application/xml; charset=UTF-8',
			},
			body,
		}),
		{
			retryOn: [404],
			accept: (status) => status === 207 || (status >= 200 && status < 300),
			label: `PROPFIND ${path}`,
		},
	)
	const text = await res.text()
	const match = text.match(/<oc:fileid[^>]*>(\d+)<\/oc:fileid>/i)
	const parsed = match ? Number(match[1]) : NaN
	if (!Number.isFinite(parsed) || parsed <= 0) {
		throw new Error(`Could not extract oc:fileid for ${path} from PROPFIND response.`)
	}
	return parsed
}

/**
 * Permission bitmask for a public link share, as OCS spells it.
 * Read alone makes a pad open read-only, which is a different code path
 * in the app — a spec that wants to compare against the pad's own
 * address has to hand out edit rights, because a read-only open
 * deliberately serves the read-only pad id or a snapshot instead.
 */
export const SHARE_PERMISSION_READ = 1
export const SHARE_PERMISSION_READ_WRITE = 3

export const createPublicShare = async (
	relativePath: string,
	permissions: number,
): Promise<{ token: string, url: string }> => {
	const body = new URLSearchParams()
	body.set('path', '/' + relativePath.replace(/^\/+/, ''))
	body.set('shareType', '3')
	body.set('permissions', String(permissions))

	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json`, {
		method: 'POST',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			Accept: 'application/json',
		},
		body,
	})
	const payload = await parseJsonResponse(res) as {
		ocs?: { meta?: { statuscode?: number, message?: string }, data?: { token?: string, url?: string } }
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`OCS share create failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	const token = String(payload?.ocs?.data?.token || '')
	const url = String(payload?.ocs?.data?.url || '')
	if (token === '' || url === '') {
		throw new Error('OCS share create response did not include token and url.')
	}
	return { token, url }
}

/** Read-only public link share — the common case. */
export const createPublicReadShare = async (relativePath: string): Promise<{ token: string, url: string }> =>
	createPublicShare(relativePath, SHARE_PERMISSION_READ)

/**
 * Create a user-to-user share (`shareType=0`) granting `shareWith`
 * read access to the file at `relativePath`. Returns the OCS share id
 * so callers can revoke through `deleteShareById`.
 */
export const createUserReadShare = async (relativePath: string, shareWith: string): Promise<{ id: string }> => {
	const body = new URLSearchParams()
	body.set('path', '/' + relativePath.replace(/^\/+/, ''))
	body.set('shareType', '0')
	body.set('shareWith', shareWith)
	body.set('permissions', '1')

	// A pad created through the UI moments ago is visible over WebDAV — the
	// spec has already read its file id — but the share API can still answer
	// 404 for the same path. Seen on a Nextcloud 31 CI runner, never on a
	// local stack, which is what a propagation race looks like. Retry that
	// one status; anything else is a real answer and is raised as-is.
	let payload: {
		ocs?: { meta?: { statuscode?: number, message?: string }, data?: { id?: string | number } }
	} = {}
	let res!: Response
	for (let attempt = 0; attempt < 5; attempt++) {
		res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json`, {
			method: 'POST',
			headers: {
				Authorization: basicAuthHeader(),
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				Accept: 'application/json',
			},
			body,
		})
		payload = await parseJsonResponse(res) as typeof payload
		if (Number(payload?.ocs?.meta?.statuscode ?? 0) !== 404) {
			break
		}
		await sleep(500 + attempt * 500)
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`OCS user-share create failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	const id = String(payload?.ocs?.data?.id ?? '')
	if (id === '') {
		throw new Error('OCS user-share create response did not include an id.')
	}
	return { id }
}

/**
 * Revoke a share by its OCS id. Used by both link-share and user-share
 * teardown paths. 404 from a stale id is treated as a successful no-op.
 */
export const deleteShareById = async (id: string): Promise<void> => {
	if (id === '') {
		return
	}
	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares/${encodeURIComponent(id)}?format=json`, {
		method: 'DELETE',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	if (!res.ok && res.status !== 404) {
		throw new Error(`OCS share delete failed with HTTP ${res.status}`)
	}
}

export const deletePublicShare = async (token: string): Promise<void> => {
	if (token === '') {
		return
	}
	const lookup = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json`, {
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	const payload = await parseJsonResponse(lookup) as {
		ocs?: { data?: Array<{ id?: string | number, token?: string }> }
	}
	const share = (payload?.ocs?.data || []).find((item) => String(item.token || '') === token)
	if (!share || share.id === undefined || share.id === null) {
		return
	}
	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares/${encodeURIComponent(String(share.id))}?format=json`, {
		method: 'DELETE',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	if (!res.ok && res.status !== 404) {
		throw new Error(`OCS share delete failed with HTTP ${res.status}`)
	}
}
