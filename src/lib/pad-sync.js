/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Shared pad-sync loop for the viewer and embed entrypoints.
 *
 * Owns the periodic background sync, forced/keepalive flushes, and the
 * visibility/pagehide lifecycle wiring. A sync on the timer says so
 * (`periodic=1`), so that the server can pace the timed syncs of a pad's
 * viewers (RecentSyncs). A forced flush asked for while a sync runs is
 * replayed once after it, to the URL it was asked for, unless the request
 * in flight is itself forced to that URL: they then share it.
 * A keepalive flush goes at once (sync()).
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
	// The request in flight: its URL, whether it is forced or timed, and its answer.
	let active = null
	// The forced flushes asked for while a request runs, by URL in the order
	// asked: each one's answer, to come with its replay.
	const replays = new Map()
	// The keepalive flush in flight for each target, until the page is shown again.
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
		// Shown again: a flush on the next leaving carries what is typed from
		// now on, so it shares no flush sent before.
		keepaliveFlushes.clear()
		if (!syncUrl || timerId !== null) {
			return
		}
		timerId = window.setInterval(() => {
			if (document.visibilityState === 'visible') {
				fireAndForget(false, false, true)
			}
		}, intervalMs)
	}

	const fireAndForget = (force, keepalive, periodic = false) => {
		void sync(force, keepalive, periodic).catch(() => {})
	}

	const request = async (url, force, keepalive, periodic = false) => {
		const flag = force ? 'force=1' : (periodic ? 'periodic=1' : '')
		const target = flag ? (url + (url.includes('?') ? '&' : '?') + flag) : url
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

	/** The request, then the replays asked for while one runs, each answering its own callers. */
	const run = async (url, force, periodic) => {
		active = { url, force: Boolean(force), periodic: Boolean(periodic) && !force, answer: request(url, force, false, periodic) }
		await active.answer.catch(() => {})
		for (const [again, replay] of replays) {
			replays.delete(again)
			active = { url: again, force: true, periodic: false, answer: request(again, true, false) }
			replay.answerWith(active.answer)
			await active.answer.catch(() => {})
		}
		// Cleared in the step that found nothing left to replay: a forced
		// flush asked for after it starts a request of its own.
		active = null
	}

	const sync = (force, keepalive, periodic = false) => syncTo(syncUrl, force, keepalive, periodic)

	const syncTo = async (url, force, keepalive, periodic) => {
		if (!url) {
			return { status: 'disabled' }
		}
		if (keepalive) {
			// The page may be leaving, and a flush queued behind the running
			// sync would then never start. The server takes the syncs of a file
			// one at a time (PadSyncLock says when it cannot), so it goes at
			// once. Leaving fires several events (pagehide, visibilitychange,
			// the host's): they share one, for the pad they leave - the viewer
			// keeps this controller when it opens another.
			const key = (force ? 'forced ' : '') + url
			if (!keepaliveFlushes.has(key)) {
				const flush = request(url, force, true).finally(() => {
					if (keepaliveFlushes.get(key) === flush) {
						keepaliveFlushes.delete(key)
					}
				})
				keepaliveFlushes.set(key, flush)
			}
			return keepaliveFlushes.get(key)
		}
		if (active) {
			if (!force && !periodic && active.periodic) {
				// The answer of a timed sync can be `deferred`, which tells
				// this one nothing: it is sent once the timed one is done, to
				// the URL it was asked for.
				return active.answer.catch(() => {}).then(() => syncTo(url, false, false, false))
			}
			if (!force || (active.force && active.url === url)) {
				return active.answer
			}
			if (!replays.has(url)) {
				let answerWith
				const answer = new Promise((resolve) => {
					answerWith = resolve
				})
				replays.set(url, { answer, answerWith })
			}
			return replays.get(url).answer
		}
		void run(url, force, periodic)
		return active.answer
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
