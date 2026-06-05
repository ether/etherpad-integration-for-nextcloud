import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPadSync, DEFAULT_SYNC_INTERVAL_MS } from '../../../src/lib/pad-sync.js'

const flush = async () => {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

const okResponse = (data = { ok: true }) => ({ ok: true, json: async () => data })

describe('pad-sync', () => {
	beforeEach(() => {
		document.dispatchEvent(new Event('visibilitychange'))
		Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
	})

	it('returns a disabled marker and makes no request when no syncUrl is set', async () => {
		const fetchImpl = vi.fn()
		const ps = createPadSync({ fetchImpl })
		const result = await ps.sync(false, false)
		expect(result).toEqual({ status: 'disabled' })
		expect(fetchImpl).not.toHaveBeenCalled()
	})

	it('posts to the sync url with the request token and no force flag for a plain sync', async () => {
		const fetchImpl = vi.fn(async () => okResponse({ status: 'ok' }))
		const ps = createPadSync({ fetchImpl, requestToken: () => 'tok-123' })
		ps.configure({ syncUrl: '/sync/42' })

		const result = await ps.sync(false, false)

		expect(result).toEqual({ status: 'ok' })
		expect(fetchImpl).toHaveBeenCalledTimes(1)
		const [url, init] = fetchImpl.mock.calls[0]
		expect(url).toBe('/sync/42')
		expect(init.method).toBe('POST')
		expect(init.credentials).toBe('same-origin')
		expect(init.headers.requesttoken).toBe('tok-123')
		expect(init.keepalive).toBe(false)
	})

	it('appends force=1 and honours keepalive for a forced flush', async () => {
		const fetchImpl = vi.fn(async () => okResponse())
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42?dir=1' })

		await ps.sync(true, true)

		const [url, init] = fetchImpl.mock.calls[0]
		expect(url).toBe('/sync/42?dir=1&force=1')
		expect(init.keepalive).toBe(true)
	})

	it('rejects with the server message on a non-ok response', async () => {
		const fetchImpl = vi.fn(async () => ({ ok: false, json: async () => ({ message: 'boom' }) }))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		await expect(ps.sync(false, false)).rejects.toThrow('boom')
	})

	it('coalesces a forced flush that arrives while a plain sync is in flight (no overlap)', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		const first = ps.sync(false, false) // in flight
		const forced = ps.sync(true, false) // must NOT start a concurrent request

		expect(fetchImpl).toHaveBeenCalledTimes(1)

		gates[0]() // finish the plain sync; the in-flight sync replays the
		await flush() // coalesced forced flush (so `first` only settles after it)

		expect(fetchImpl).toHaveBeenCalledTimes(2)
		expect(fetchImpl.mock.calls[1][0]).toBe('/sync/42?force=1')

		gates[1]() // finish the forced replay
		await Promise.all([first, forced])
		expect(fetchImpl).toHaveBeenCalledTimes(2)
	})

	it('does not replay a coalesced forced flush once the url is cleared mid-flight (pad-switch safety)', async () => {
		// The viewer resets via configure({ syncUrl: '' }) before switching
		// pads. A forced flush that was queued against the old pad must then
		// become a no-op instead of POSTing to a stale/replaced target.
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/A' })

		const inflight = ps.sync(false, false) // to /sync/A, in flight
		const forced = ps.sync(true, false) // coalesced, pending replay
		expect(fetchImpl).toHaveBeenCalledTimes(1)

		ps.configure({ syncUrl: '' }) // viewer clears before re-resolving

		gates[0]() // finish the in-flight sync; the replay now sees no url
		await Promise.all([inflight, forced])
		await flush()

		expect(fetchImpl).toHaveBeenCalledTimes(1) // replay was a no-op
		await expect(forced).resolves.toEqual({ status: 'disabled' })
	})

	it('start() runs a sync per interval only while the document is visible; stop() halts it', async () => {
		vi.useFakeTimers()
		const fetchImpl = vi.fn(async () => okResponse())
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42', intervalMs: 1000 })

		ps.start()
		vi.advanceTimersByTime(1000)
		expect(fetchImpl).toHaveBeenCalledTimes(1)

		Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
		vi.advanceTimersByTime(1000)
		expect(fetchImpl).toHaveBeenCalledTimes(1) // skipped while hidden

		Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
		ps.stop()
		vi.advanceTimersByTime(5000)
		expect(fetchImpl).toHaveBeenCalledTimes(1) // stopped
	})

	it('start() is a no-op without a syncUrl and uses the default interval otherwise', () => {
		vi.useFakeTimers()
		const setInterval = vi.spyOn(window, 'setInterval')
		const ps = createPadSync({ fetchImpl: vi.fn() })

		ps.start()
		expect(setInterval).not.toHaveBeenCalled()

		ps.configure({ syncUrl: '/sync/42' })
		ps.start()
		expect(setInterval).toHaveBeenCalledWith(expect.any(Function), DEFAULT_SYNC_INTERVAL_MS)
	})

	it('installLifecycleHandlers wires pagehide to a forced keepalive flush; remove unwires it', async () => {
		const fetchImpl = vi.fn(async () => okResponse())
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		ps.installLifecycleHandlers()
		window.dispatchEvent(new Event('pagehide'))
		await flush()
		expect(fetchImpl).toHaveBeenCalledTimes(1)
		expect(fetchImpl.mock.calls[0][0]).toBe('/sync/42?force=1')
		expect(fetchImpl.mock.calls[0][1].keepalive).toBe(true)

		ps.removeLifecycleHandlers()
		window.dispatchEvent(new Event('pagehide'))
		await flush()
		expect(fetchImpl).toHaveBeenCalledTimes(1) // no further flush after removal
	})
})
