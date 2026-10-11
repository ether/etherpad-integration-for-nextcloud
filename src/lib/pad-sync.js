/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Shared pad-sync loop for the viewer and embed entrypoints.
 *
 * Owns the periodic background sync, forced/keepalive flushes, and the
 * visibility/pagehide lifecycle wiring. Forced syncs coalesce: while one
 * request is in flight, a second forced flush is not started beside it but
 * remembered and replayed once. A keepalive flush is the exception (sync()):
 * the page is leaving, so it goes at once.
 *
 * The caller supplies the request-token getter (the viewer and embed read it
 * from different places) and may inject a `fetchImpl` for tests.
 */

export const DEFAULT_SYNC_INTERVAL_MS = 120000

export function createPadSync({ requestToken, fetchImpl } = {}) {
	const doFetch = typeof fetchImpl === 'function' ? fetchImpl : (...args) => window.fetch(...args)
	const getToken = typeof requestToken === 'function' ? requestToken : () => ''

	let syncUrl = ''
	let intervalMs = DEFAULT_SYNC_INTERVAL_MS
	// The running sync, a forced flush asked for while it ran included.
	let syncPromise = null
	let activeSyncForce = false
	let replayForced = false
	let timerId = null
	let visibilityHandler = null
	let pageHideHandler = null

	const configure = ({ syncUrl: url, intervalMs: ms } = {}) => {
		if (typeof url === 'string') {
			syncUrl = url
		}
		if (Number.isFinite(ms) && ms > 0) {
			intervalMs = ms
		}
	}

	const stop = () => {
		if (timerId !== null) {
			window.clearInterval(timerId)
			timerId = null
		}
	}

	const start = () => {
		if (!syncUrl || timerId !== null) {
			return
		}
		timerId = window.setInterval(() => {
			if (document.visibilityState === 'visible') {
				fireAndForget(false, false)
			}
		}, intervalMs)
	}

	const fireAndForget = (force, keepalive) => {
		void sync(force, keepalive).catch(() => {})
	}

	const request = async (force, keepalive) => {
		const url = force ? (syncUrl + (syncUrl.includes('?') ? '&' : '?') + 'force=1') : syncUrl
		const response = await doFetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				requesttoken: getToken(),
			},
			keepalive: Boolean(keepalive),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Sync request failed.')
		}
		return data
	}

	/**
	 * The request, then once more, forced, if a forced flush was asked for
	 * while it ran: the replay's answer, or its error, is then every waiting
	 * caller's.
	 */
	const run = async (force, keepalive) => {
		const first = request(force, keepalive)
		await first.catch(() => {})
		if (!replayForced) {
			return first
		}
		replayForced = false
		activeSyncForce = true
		// A pad switched meanwhile cleared the url: nothing to flush.
		return syncUrl ? request(true, false) : { status: 'disabled' }
	}

	const sync = async (force, keepalive) => {
		if (!syncUrl) {
			return { status: 'disabled' }
		}
		if (syncPromise) {
			// The page is leaving: queued behind the running request, the
			// flush would never start. It goes at once, beside it.
			if (keepalive) {
				return request(force, true)
			}
			if (force && !activeSyncForce) {
				replayForced = true
			}
			return syncPromise
		}
		activeSyncForce = Boolean(force)
		syncPromise = run(force, keepalive)
		try {
			return await syncPromise
		} finally {
			syncPromise = null
			activeSyncForce = false
		}
	}

	const installLifecycleHandlers = () => {
		if (visibilityHandler || pageHideHandler) {
			return
		}
		visibilityHandler = () => {
			if (document.visibilityState === 'hidden') {
				fireAndForget(true, true)
				stop()
				return
			}
			start()
		}
		pageHideHandler = () => {
			fireAndForget(true, true)
			stop()
		}
		document.addEventListener('visibilitychange', visibilityHandler)
		window.addEventListener('pagehide', pageHideHandler)
	}

	const removeLifecycleHandlers = () => {
		if (visibilityHandler) {
			document.removeEventListener('visibilitychange', visibilityHandler)
			visibilityHandler = null
		}
		if (pageHideHandler) {
			window.removeEventListener('pagehide', pageHideHandler)
			pageHideHandler = null
		}
	}

	return {
		configure,
		start,
		stop,
		sync,
		fireAndForget,
		installLifecycleHandlers,
		removeLifecycleHandlers,
	}
}
