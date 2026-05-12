/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const DEFAULT_REQUEST_TIMEOUT_MS = 10000

export const fetchJsonWithTimeout = async (url, init = {}, timeoutMs = DEFAULT_REQUEST_TIMEOUT_MS) => {
	const controller = new AbortController()
	const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs)
	const headers = Object.assign({ Accept: 'application/json' }, init.headers || {})
	try {
		const response = await fetch(url, Object.assign({}, init, {
			credentials: 'same-origin',
			headers,
			signal: controller.signal,
		}))
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Request failed.')
		}
		return data
	} catch (error) {
		if (error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') {
			throw new Error('Request timed out.')
		}
		throw error
	} finally {
		window.clearTimeout(timeoutId)
	}
}
