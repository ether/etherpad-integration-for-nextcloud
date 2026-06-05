/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Shared pad-sync loop for the viewer and embed entrypoints.
 *
 * Owns the periodic background sync, forced/keepalive flushes, and the
 * visibility/pagehide lifecycle wiring. Forced syncs coalesce: while one
 * request is in flight a second forced flush is not started concurrently but
 * remembered and replayed once, so two forced flushes can never overlap (this
 * is the embed behavior, now shared — the viewer previously used a plain
 * in-flight boolean that allowed overlap).
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
	let syncPromise = null
	let activeSyncForce = false
	let pendingForcedSync = false
	let pendingForcedKeepalive = false
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

	const sync = async (force, keepalive) => {
		if (!syncUrl) {
			return { status: 'disabled' }
		}
		if (syncPromise) {
			// A request is already running. A forced flush that arrives while a
			// non-forced sync is in flight is coalesced into a single replay.
			if (force && !activeSyncForce) {
				pendingForcedSync = true
				pendingForcedKeepalive = pendingForcedKeepalive || Boolean(keepalive)
				return syncPromise.catch(() => undefined).then(() => sync(true, pendingForcedKeepalive))
			}
			return syncPromise
		}
		activeSyncForce = Boolean(force)
		const currentPromise = (async () => {
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
		})()
		syncPromise = currentPromise
		let result
		let syncError = null
		try {
			result = await currentPromise
		} catch (error) {
			syncError = error
		} finally {
			if (syncPromise === currentPromise) {
				syncPromise = null
			}
			activeSyncForce = false
		}
		const rerunForcedSync = pendingForcedSync
		const rerunKeepalive = pendingForcedKeepalive
		pendingForcedSync = false
		pendingForcedKeepalive = false
		if (rerunForcedSync) {
			return sync(true, rerunKeepalive)
		}
		if (syncError instanceof Error) {
			throw syncError
		}
		return result
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
