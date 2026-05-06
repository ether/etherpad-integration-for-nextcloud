/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'
import { ocGenerateUrl, ocRequestToken } from './oc-compat.js'

const RESOLVE_CACHE = new Map()

export const apiResolvePadByFileId = async (fileId) => {
	const cacheKey = String(fileId)
	if (RESOLVE_CACHE.has(cacheKey)) {
		return RESOLVE_CACHE.get(cacheKey)
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
	RESOLVE_CACHE.set(cacheKey, request)
	return request
}

export const apiResolvePadByPath = async (path) => {
	const cacheKey = 'path:' + String(path)
	if (RESOLVE_CACHE.has(cacheKey)) {
		return RESOLVE_CACHE.get(cacheKey)
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
	RESOLVE_CACHE.set(cacheKey, request)
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

const fetchJson = async (url, options, fallbackMessage) => {
	const response = await fetch(url, {
		...options,
		credentials: 'same-origin',
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error((data && data.message) || fallbackMessage)
	}
	return data
}
