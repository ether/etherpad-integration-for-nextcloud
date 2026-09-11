/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Client helpers for pad resolution and snapshot recovery. */

import { APP_ID } from './constants.js'
import { ocGenerateUrl, ocRequestToken } from './oc-compat.js'
import { fetchJsonWithTimeout } from './fetch-helpers.js'

const RESOLVE_CACHE = new Map()
const RESOLVE_CACHE_MAX_ENTRIES = 50
const RESOLVE_CACHE_TTL_MS = 5 * 60 * 1000

/**
 * Resolve a path to its pad metadata.
 *
 * Bypass the cache before a write. An entry is up to five minutes old, and
 * in five minutes a file can be moved and another `.pad` created at the
 * same path: for a read that is stale, for recovery it would bind a pad to
 * the wrong file.
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
	// A client timeout would not stop the server-side provisioning work.
	const result = await fetchJsonWithTimeout(endpoint, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			requesttoken: ocRequestToken(),
		},
	}, { fallbackMessage: 'Recovery failed.', timeoutMs: null })
	// Only this path: flushing every entry would throw away answers for
	// unrelated files the session has already looked up.
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

const fetchJson = async (url, options, fallbackMessage) =>
	fetchJsonWithTimeout(url, options, { fallbackMessage })
