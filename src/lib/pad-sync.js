/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Shared pad-sync loop for the viewer and embed entrypoints.
 *
 * Owns the periodic background sync, forced/keepalive flushes, and the
 * visibility/pagehide lifecycle wiring. A forced flush asked for while a
 * sync runs is replayed once after it, to the URL it was asked for. A
 * keepalive flush goes at once (sync()).
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
	// The running sync, its replay included.
	let syncPromise = null
	let activeSyncForce = false
	let replayUrl = null
	// The keepalive flush in flight for each URL.
	const keepaliveFlushes = new Map()
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

	const request = async (url, force, keepalive) => {
		const target = force ? (url + (url.includes('?') ? '&' : '?') + 'force=1') : url
		const response = await doFetch(target, {
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

	/** The request, then its replay if one was asked for: the last answer is every waiting caller's. */
	const run = async (url, force) => {
		const first = request(url, force, false)
		await first.catch(() => {})
		if (replayUrl === null) {
			return first
		}
		const again = replayUrl
		replayUrl = null
		activeSyncForce = true
		return request(again, true, false)
	}

	const sync = async (force, keepalive) => {
		if (!syncUrl) {
			return { status: 'disabled' }
		}
		if (keepalive) {
			// The page may be leaving, and a flush queued behind the running
			// sync would then never start. The server runs two syncs of a file
			// one after the other, so it goes at once. Leaving fires several
			// events (pagehide, visibilitychange, the host's): they share one,
			// for the pad they leave - the viewer keeps this controller when it
			// opens another.
			const url = syncUrl
			if (!keepaliveFlushes.has(url)) {
				keepaliveFlushes.set(url, request(url, force, true).finally(() => {
					keepaliveFlushes.delete(url)
				}))
			}
			return keepaliveFlushes.get(url)
		}
		if (syncPromise) {
			if (force && !activeSyncForce) {
				replayUrl = syncUrl
			}
			return syncPromise
		}
		activeSyncForce = Boolean(force)
		syncPromise = run(syncUrl, force)
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
