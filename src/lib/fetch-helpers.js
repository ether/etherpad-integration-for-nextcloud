/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const DEFAULT_REQUEST_TIMEOUT_MS = 10000
const GATEWAY_STATUSES = [502, 503, 504]

/**
 * `init.signal` is chained rather than replaced: the timeout needs a
 * controller of its own, and a caller that brought a signal — a viewer
 * abandoning an open, say — must still be able to cancel. Whichever fires
 * first wins.
 *
 * `timeoutMs: null` waits indefinitely, and is for requests that *write*.
 * Cutting a read short costs a retry; cutting a write short applies the
 * change with nobody left to read the outcome — a recovery that has
 * created its pad but not yet its binding looks unrecovered, and the
 * retry then either collides with the binding it did write or provisions
 * a second pad and orphans the first. Slow is not the same as stuck.
 *
 * An error carries the server's `code` and `retryable` when it sent them,
 * and `unanswered` when nothing came back from this app: our own timeout,
 * a failed network, or a gateway answering in its place. Only that fact:
 * whether the same request is worth another try depends on whether it
 * writes, which the caller knows (`isRetryableOpenError` for an open).
 */
export const fetchJsonWithTimeout = async (url, init = {}, options = {}) => {
	const { timeoutMs = DEFAULT_REQUEST_TIMEOUT_MS, fallbackMessage = 'Request failed.' } = options
	const controller = new AbortController()
	const timeoutId = timeoutMs === null || timeoutMs === 0
		? null
		: window.setTimeout(() => controller.abort(), timeoutMs)
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
		// Only a body that is not JSON counts as none. A timeout or a failed
		// network while it streams in is no answer, and is handled below.
		const data = await response.json().catch((error) => {
			if (!(error instanceof SyntaxError)) {
				throw error
			}
			return {}
		})
		if (!response.ok) {
			const error = new Error((data && data.message) || fallbackMessage)
			if (data && typeof data.code === 'string') {
				error.code = data.code
			}
			// The server's word that the same request may succeed later (the
			// cases are in docs/api-reference.md).
			if (data && data.retryable === true) {
				error.retryable = true
			}
			// This app answers an error with a `message`. A proxy with its
			// backend gone, or Nextcloud in maintenance, sends a page of its
			// own, or JSON of another shape.
			if (GATEWAY_STATUSES.includes(response.status) && !(data && typeof data.message === 'string')) {
				error.unanswered = true
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
			if (timeoutId === null || (callerSignal && callerSignal.aborted)) {
				throw error
			}
			const timedOut = new Error('Request timed out.')
			timedOut.unanswered = true
			throw timedOut
		}
		// fetch() and reading the body reject with a TypeError when the
		// network fails.
		if (error instanceof TypeError) {
			error.unanswered = true
		}
		throw error
	} finally {
		if (timeoutId !== null) {
			window.clearTimeout(timeoutId)
		}
		if (abortOnCaller) {
			callerSignal.removeEventListener('abort', abortOnCaller)
		}
	}
}

/**
 * What to show for a failed request: the server's sentence, or, when
 * nothing came back, the caller's translated `unansweredText` rather than
 * the browser's own English ("Failed to fetch", "Load failed", ...).
 *
 * @param {unknown} error
 * @param {string} unansweredText
 * @param {string} fallbackText
 * @return {string}
 */
export const requestErrorMessage = (error, unansweredText, fallbackText) => {
	if (error && error.unanswered === true) {
		return unansweredText
	}
	return error instanceof Error && error.message ? error.message : fallbackText
}
