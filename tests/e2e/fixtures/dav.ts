/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

const basicAuthHeader = (): string => {
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
 * Delete a file or folder through WebDAV. Used for teardown so browser
 * specs do not leave pads behind on a shared target instance.
 */
export const deleteViaDav = async (relativePath: string): Promise<void> => {
	const path = relativePath.replace(/^\/+/, '')
	const res = await fetch(davUrl(path), {
		method: 'DELETE',
		headers: { Authorization: basicAuthHeader() },
	})
	if (!res.ok && res.status !== 404) {
		throw new Error(`WebDAV cleanup DELETE ${path} failed with HTTP ${res.status}`)
	}
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

/**
 * Server-side WebDAV COPY. The copy receives a new file id, so any
 * existing binding row stays attached to the source — the destination
 * shows up as an orphaned .pad in the viewer flow. Used by the orphan-
 * recovery spec to set up that exact state without poking the DB.
 */
export const copyViaDav = async (srcRelativePath: string, destRelativePath: string): Promise<void> => {
	const srcPath = srcRelativePath.replace(/^\/+/, '')
	const destPath = destRelativePath.replace(/^\/+/, '')
	const res = await fetch(davUrl(srcPath), {
		method: 'COPY',
		headers: {
			Authorization: basicAuthHeader(),
			Destination: davUrl(destPath),
			Overwrite: 'F',
		},
	})
	if (!res.ok && res.status !== 201 && res.status !== 204) {
		throw new Error(`WebDAV COPY ${srcPath} -> ${destPath} failed with HTTP ${res.status}`)
	}
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
	const body = '<?xml version="1.0"?>\n'
		+ '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns">\n'
		+ '  <d:prop><nc:trashbin-filename/><oc:trashbin-original-filename/></d:prop>\n'
		+ '</d:propfind>'
	const res = await fetch(trashbinUrl('trash'), {
		method: 'PROPFIND',
		headers: {
			Authorization: basicAuthHeader(),
			Depth: '1',
			'Content-Type': 'application/xml; charset=UTF-8',
		},
		body,
	})
	if (!res.ok && res.status !== 207) {
		throw new Error(`WebDAV PROPFIND trashbin failed with HTTP ${res.status}`)
	}
	const xml = await res.text()
	// Parse minimally: walk each <d:response>, extract href + the
	// original-filename property, match against the requested name.
	const responseChunks = xml.split(/<d:response[\s>]/i).slice(1)
	for (const chunk of responseChunks) {
		const hrefMatch = chunk.match(/<d:href[^>]*>([^<]+)<\/d:href>/i)
		const originalMatch = chunk.match(/<(?:oc:trashbin-original-filename|nc:trashbin-filename)[^>]*>([^<]+)<\/(?:oc:trashbin-original-filename|nc:trashbin-filename)>/i)
		if (!hrefMatch || !originalMatch) {
			continue
		}
		if (decodeURIComponent(originalMatch[1].trim()) !== originalFileName) {
			continue
		}
		// Strip leading /remote.php/dav/trashbin/<user>/ so the caller can
		// recompose paths via trashbinUrl().
		const href = decodeURIComponent(hrefMatch[1].trim())
		const prefix = `/remote.php/dav/trashbin/${E2E.user}/`
		if (href.startsWith(prefix)) {
			return href.slice(prefix.length).replace(/\/+$/, '')
		}
	}
	return null
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
	const res = await fetch(trashbinUrl(entry), {
		method: 'MOVE',
		headers: {
			Authorization: basicAuthHeader(),
			Destination: trashbinUrl('restore/' + originalFileName),
		},
	})
	if (!res.ok && res.status !== 201 && res.status !== 204) {
		throw new Error(`WebDAV trashbin restore MOVE failed with HTTP ${res.status}`)
	}
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
	const res = await fetch(davUrl(path), {
		method: 'PROPFIND',
		headers: {
			Authorization: basicAuthHeader(),
			Depth: '0',
			'Content-Type': 'application/xml; charset=UTF-8',
		},
		body,
	})
	if (!res.ok && res.status !== 207) {
		throw new Error(`WebDAV PROPFIND ${path} failed with HTTP ${res.status}`)
	}
	const text = await res.text()
	const match = text.match(/<oc:fileid[^>]*>(\d+)<\/oc:fileid>/i)
	const parsed = match ? Number(match[1]) : NaN
	if (!Number.isFinite(parsed) || parsed <= 0) {
		throw new Error(`Could not extract oc:fileid for ${path} from PROPFIND response.`)
	}
	return parsed
}

export const createPublicReadShare = async (relativePath: string): Promise<{ token: string, url: string }> => {
	const body = new URLSearchParams()
	body.set('path', '/' + relativePath.replace(/^\/+/, ''))
	body.set('shareType', '3')
	body.set('permissions', '1')

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
