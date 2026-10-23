import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushAsyncWork } from '../flush.js'
import { createPadSync, DEFAULT_SYNC_INTERVAL_MS } from '../../../src/lib/pad-sync.js'

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

	it('sends force=1 alone for a forced sync, even one said to be periodic', async () => {
		const fetchImpl = vi.fn(async () => okResponse())
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		await ps.sync(true, false, true)

		expect(fetchImpl.mock.calls[0][0]).toBe('/sync/42?force=1')
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

		gates[0]() // finish the plain sync; the coalesced forced flush is
		await flushAsyncWork() // replayed after it

		expect(fetchImpl).toHaveBeenCalledTimes(2)
		expect(fetchImpl.mock.calls[1][0]).toBe('/sync/42?force=1')

		gates[1]() // finish the forced replay
		await Promise.all([first, forced])
		expect(fetchImpl).toHaveBeenCalledTimes(2)
	})

	// The url changed while the replay waits: it goes to the url it was asked for.
	it('replays a forced flush to the url it was asked for', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/A' })

		const inflight = ps.sync(false, false)
		const forced = ps.sync(true, false)
		ps.configure({ syncUrl: '/sync/B' })

		gates[0]()
		await flushAsyncWork()
		expect(fetchImpl).toHaveBeenCalledTimes(2)
		expect(fetchImpl.mock.calls[1][0]).toBe('/sync/A?force=1')

		gates[1]()
		await Promise.all([inflight, forced])
	})

	// Queued behind the running sync, the flush would never start once the
	// page is gone; the server runs the two one after the other.
	it('sends a keepalive flush at once, beside a running sync', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		const running = ps.sync(false, false)
		const closing = ps.sync(true, true)

		expect(fetchImpl).toHaveBeenCalledTimes(2)
		expect(fetchImpl.mock.calls[1][0]).toBe('/sync/42?force=1')
		expect(fetchImpl.mock.calls[1][1].keepalive).toBe(true)

		gates.forEach((open) => open())
		await Promise.all([running, closing])
		expect(fetchImpl).toHaveBeenCalledTimes(2)
	})

	// The viewer keeps one controller and opens the next pad with it.
	it('does not let another pad share the keepalive flush of the one left', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/A' })

		const leftA = ps.sync(true, true)
		ps.configure({ syncUrl: '/sync/B' })
		const leftB = ps.sync(true, true)

		expect(fetchImpl).toHaveBeenCalledTimes(2)
		expect(fetchImpl.mock.calls[1][0]).toBe('/sync/B?force=1')
		gates.forEach((open) => open())
		await Promise.all([leftA, leftB])
	})

	it('lets the events of one leaving share a keepalive flush', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })
		ps.installLifecycleHandlers()

		try {
			Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
			document.dispatchEvent(new Event('visibilitychange'))
			window.dispatchEvent(new Event('pagehide'))
			const host = ps.sync(true, true)
			await flushAsyncWork()
			expect(fetchImpl).toHaveBeenCalledTimes(1)

			gates[0]()
			await host
			ps.fireAndForget(true, true)
			await flushAsyncWork()
			expect(fetchImpl).toHaveBeenCalledTimes(2)
			gates[1]()
		} finally {
			ps.removeLifecycleHandlers()
		}
	})

	// Hidden, shown, typed in, left: the flush on leaving carries the new
	// edits, which the one still in flight from hiding may have missed.
	it('shares no keepalive flush across the page being shown again', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		const hidden = ps.sync(true, true)
		ps.start()
		ps.stop()
		const left = ps.sync(true, true)
		const plainLeft = ps.sync(false, true)

		expect(fetchImpl).toHaveBeenCalledTimes(3)
		expect(fetchImpl.mock.calls[2][0]).toBe('/sync/42')
		gates.forEach((open) => open())
		await Promise.all([hidden, left, plainLeft])
	})

	it('ends the sharing when the page is shown again, through its own handlers', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })
		ps.installLifecycleHandlers()
		const show = (state) => {
			Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
			document.dispatchEvent(new Event('visibilitychange'))
		}

		try {
			show('hidden')
			show('visible')
			show('hidden')
			await flushAsyncWork()
			expect(fetchImpl).toHaveBeenCalledTimes(2)
			gates.forEach((open) => open())
		} finally {
			ps.removeLifecycleHandlers()
			ps.stop()
		}
	})

	// The flush from before the page was shown again settles while the next
	// one is in flight: it removes only its own entry, and the next leaving
	// still shares the one in flight.
	it('lets a settled flush leave the one that replaced it shared', async () => {
		const gates = []
		const fetchImpl = vi.fn(() => new Promise((resolve) => {
			gates.push(() => resolve(okResponse()))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42' })

		const hidden = ps.sync(true, true)
		ps.start()
		ps.stop()
		const left = ps.sync(true, true)
		gates[0]()
		await hidden
		const leftAgain = ps.sync(true, true)

		expect(fetchImpl).toHaveBeenCalledTimes(2)
		gates[1]()
		await Promise.all([left, leftAgain])
	})

	it('replays each url forced flushes asked for while a sync ran, in order, each with its own answer', async () => {
		const gates = []
		const fetchImpl = vi.fn((url) => new Promise((resolve) => {
			gates.push(() => resolve(okResponse({ url })))
		}))
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/A' })

		const running = ps.sync(false, false)
		const forA = ps.sync(true, false)
		ps.configure({ syncUrl: '/sync/B' })
		const forB = ps.sync(true, false)

		for (let i = 0; i < 3; i++) {
			await flushAsyncWork()
			gates[i]()
		}
		expect(await Promise.all([running, forA, forB])).toEqual([{ url: '/sync/A' }, { url: '/sync/A?force=1' }, { url: '/sync/B?force=1' }])
		expect(fetchImpl).toHaveBeenCalledTimes(3)
	})

	it('start() runs a sync per interval only while the document is visible; stop() halts it', async () => {
		vi.useFakeTimers()
		const fetchImpl = vi.fn(async () => okResponse())
		const ps = createPadSync({ fetchImpl })
		ps.configure({ syncUrl: '/sync/42', intervalMs: 1000 })

		ps.start()
		vi.advanceTimersByTime(1000)
		expect(fetchImpl).toHaveBeenCalledTimes(1)
		// Said to be on the timer, so that the server can pace it.
		expect(fetchImpl.mock.calls[0][0]).toBe('/sync/42?periodic=1')

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
		await flushAsyncWork()
		expect(fetchImpl).toHaveBeenCalledTimes(1)
		expect(fetchImpl.mock.calls[0][0]).toBe('/sync/42?force=1')
		expect(fetchImpl.mock.calls[0][1].keepalive).toBe(true)

		ps.removeLifecycleHandlers()
		window.dispatchEvent(new Event('pagehide'))
		await flushAsyncWork()
		expect(fetchImpl).toHaveBeenCalledTimes(1) // no further flush after removal
	})
})
