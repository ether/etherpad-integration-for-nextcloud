/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'
import { ocGenerateUrl, ocRequestToken } from './oc-compat.js'
import { fetchJsonWithTimeout } from './fetch-helpers.js'

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

/**
 * Resolve a path to its pad metadata.
 *
 * `bypassCache` is for callers whose next step *writes*. A cached entry
 * is up to five minutes old, and in five minutes a file can be moved and
 * another `.pad` created at the same path — the answer would then name a
 * document the user is not looking at. For an open that is a stale read;
 * for recovery it would create and bind a pad against the wrong file,
 * which is the substitution this whole area exists to prevent. The fresh
 * answer still refreshes the cache, so nothing is left stale behind it.
 */
export const apiResolvePadByPath = async (path, { bypassCache = false } = {}) => {
	const cacheKey = 'path:' + String(path)
	const cached = bypassCache ? null : getResolveCache(cacheKey)
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

export const apiFindOriginalPad = async (fileId) => {
	const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/find-original/' + encodeURIComponent(String(fileId)))
	return fetchJson(endpoint, {
		method: 'GET',
		headers: { Accept: 'application/json' },
	}, 'Lookup failed.')
}

export const apiRecoverFromSnapshot = async (fileId, path = '') => {
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
	// And the path the caller resolved this id by, if it named one. The
	// same answer is cached under both keys; flushing every `path:` entry
	// instead would throw away answers for unrelated files a session has
	// already looked up.
	if (typeof path === 'string' && path !== '') {
		RESOLVE_CACHE.delete('path:' + path)
	}
	return result
}


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

/**
 * The shared helper, with this module's per-call wording. It was a third
 * copy of the same code without the helper's timeout — and one of these
 * calls sits inside the viewer's open flow, where a request that never
 * settles leaves "Loading pad..." on screen with no error and no way out.
 */
const fetchJson = async (url, options, fallbackMessage) =>
	fetchJsonWithTimeout(url, options, { fallbackMessage })
