/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'
import { ocGenerateUrl, ocRequestToken } from './oc-compat.js'

const RESOLVE_CACHE = new Map()
const RESOLVE_CACHE_MAX_ENTRIES = 50
const RESOLVE_CACHE_TTL_MS = 5 * 60 * 1000

export const apiResolvePadByFileId = async (fileId) => {
	const cacheKey = String(fileId)
	const cached = getResolveCache(cacheKey)
	if (cached !== null) {
		return cached
	}
	const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/resolve') + '?fileId=' + encodeURIComponent(cacheKey)
	const request = fetchJson(url, {
		method: 'GET',
		headers: { Accept: 'application/json' },
	}, 'Pad resolve failed.')
		.catch((error) => {
			RESOLVE_CACHE.delete(cacheKey)
			throw error
		})
	setResolveCache(cacheKey, request)
	return request
}

export const apiResolvePadByPath = async (path) => {
	const cacheKey = 'path:' + String(path)
	const cached = getResolveCache(cacheKey)
	if (cached !== null) {
		return cached
	}
	const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/resolve') + '?file=' + encodeURIComponent(path)
	const request = fetchJson(url, {
		method: 'GET',
		headers: { Accept: 'application/json' },
	}, 'Pad resolve by path failed.')
		.catch((error) => {
			RESOLVE_CACHE.delete(cacheKey)
			throw error
		})
	setResolveCache(cacheKey, request)
	return request
}

export const apiSyncStatusByFileId = async (fileId) => {
	const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/sync-status/' + encodeURIComponent(String(fileId)))
	return fetchJson(endpoint, {
		method: 'GET',
		headers: {
			Accept: 'application/json',
		},
	}, 'Sync status check failed.')
}

export const apiSyncByFileId = async (fileId, force) => {
	let endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/sync/' + encodeURIComponent(String(fileId)))
	if (force) {
		endpoint += '?force=1'
	}
	return fetchJson(endpoint, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			requesttoken: ocRequestToken(),
		},
	}, 'Pad sync failed.')
}

export const apiCreatePadFromUrl = async (filePath, padUrl) => {
	const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/from-url')
	const body = new URLSearchParams()
	body.set('file', filePath)
	body.set('padUrl', padUrl)

	return fetchJson(endpoint, {
		method: 'POST',
		headers: formHeaders(),
		body: body.toString(),
	}, 'Could not import public pad URL.')
}

export const apiRecoverFromSnapshot = async (fileId) => {
	const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/recover-from-snapshot/' + encodeURIComponent(String(fileId)))
	const result = await fetchJson(endpoint, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			requesttoken: ocRequestToken(),
		},
	}, 'Recovery failed.')
	// A freshly recovered pad invalidates any cached resolve response: the
	// old one carried a missing-binding marker that no longer applies.
	RESOLVE_CACHE.delete(String(fileId))
	return result
}

export const apiCreatePublicPad = async (filePath) => {
	const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads')
	const body = new URLSearchParams()
	body.set('file', filePath)
	body.set('accessMode', 'public')

	return fetchJson(endpoint, {
		method: 'POST',
		headers: formHeaders(),
		body: body.toString(),
	}, 'Could not create public pad.')
}

const formHeaders = () => ({
	Accept: 'application/json',
	'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
	requesttoken: ocRequestToken(),
})

const getResolveCache = (cacheKey) => {
	const cached = RESOLVE_CACHE.get(cacheKey)
	if (!cached) {
		return null
	}
	if ((Date.now() - cached.createdAt) > RESOLVE_CACHE_TTL_MS) {
		RESOLVE_CACHE.delete(cacheKey)
		return null
	}
	return cached.request
}

const setResolveCache = (cacheKey, request) => {
	if (!RESOLVE_CACHE.has(cacheKey) && RESOLVE_CACHE.size >= RESOLVE_CACHE_MAX_ENTRIES) {
		const oldestKey = RESOLVE_CACHE.keys().next().value
		if (oldestKey !== undefined) {
			RESOLVE_CACHE.delete(oldestKey)
		}
	}
	RESOLVE_CACHE.set(cacheKey, {
		createdAt: Date.now(),
		request,
	})
}

const fetchJson = async (url, options, fallbackMessage) => {
	const response = await fetch(url, {
		...options,
		credentials: 'same-origin',
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		const error = new Error((data && data.message) || fallbackMessage)
		if (data && typeof data.code === 'string') {
			error.code = data.code
		}
		error.status = response.status
		throw error
	}
	return data
}
