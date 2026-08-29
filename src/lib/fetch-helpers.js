/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const DEFAULT_REQUEST_TIMEOUT_MS = 10000

/**
 * `init.signal` is chained rather than replaced: the timeout needs a
 * controller of its own, and a caller that brought a signal — a viewer
 * abandoning an open, say — must still be able to cancel. Whichever fires
 * first wins.
 */
export const fetchJsonWithTimeout = async (url, init = {}, options = {}) => {
	const { timeoutMs = DEFAULT_REQUEST_TIMEOUT_MS, fallbackMessage = 'Request failed.' } = options
	const controller = new AbortController()
	const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs)
	const callerSignal = init.signal
	let abortOnCaller
	if (callerSignal) {
		if (callerSignal.aborted) {
			controller.abort()
		} else {
			abortOnCaller = () => controller.abort()
			callerSignal.addEventListener('abort', abortOnCaller)
		}
	}
	const headers = Object.assign({ Accept: 'application/json' }, init.headers || {})
	try {
		const response = await fetch(url, Object.assign({}, init, {
			credentials: 'same-origin',
			headers,
			signal: controller.signal,
		}))
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
	} catch (error) {
		if (error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') {
			// A caller's own abort is not a timeout. No caller distinguishes
			// them today — both recognise a superseded request by the
			// generation guard instead — but rewriting a deliberate abort as
			// "Request timed out." would be a lie the moment one does.
			if (callerSignal && callerSignal.aborted) {
				throw error
			}
			throw new Error('Request timed out.')
		}
		throw error
	} finally {
		window.clearTimeout(timeoutId)
		if (abortOnCaller) {
			callerSignal.removeEventListener('abort', abortOnCaller)
		}
	}
}
