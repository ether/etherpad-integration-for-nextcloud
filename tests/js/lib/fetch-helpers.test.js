/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchJsonWithTimeout } from '../../../src/lib/fetch-helpers.js'

/**
 * The signal chaining is the subtlest part of this module and it now
 * carries both frontends: the viewer aborts a superseded open, the embed
 * relies on the timeout, and both go through here.
 */
const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

const stubFetch = (impl) => {
	const mock = typeof impl === 'function' ? vi.fn(impl) : vi.fn().mockResolvedValue(impl)
	vi.stubGlobal('fetch', mock)
	return mock
}

const abortError = () => new DOMException('The operation was aborted.', 'AbortError')

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('fetchJsonWithTimeout', () => {
	it('asks for JSON and keeps the caller headers', async () => {
		const fetchMock = stubFetch(jsonResponse({ ok: true }))

		await fetchJsonWithTimeout('/x', { method: 'POST', headers: { requesttoken: 't' } })

		const sent = fetchMock.mock.calls[0][1]
		expect(sent.headers).toEqual({ Accept: 'application/json', requesttoken: 't' })
		expect(sent.credentials).toBe('same-origin')
		expect(sent.method).toBe('POST')
	})

	it('carries status and code out of an error response', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))

		await expect(fetchJsonWithTimeout('/x')).rejects.toMatchObject({
			message: 'no binding',
			status: 400,
			code: 'missing_binding',
		})
	})

	it('uses the caller wording when the response carries no message', async () => {
		stubFetch(jsonResponse({}, false, 500))

		await expect(fetchJsonWithTimeout('/x', {}, { fallbackMessage: 'Recovery failed.' }))
			.rejects.toThrow('Recovery failed.')
	})

	it('reports a request that never answers as a timeout', async () => {
		vi.useFakeTimers()
		stubFetch((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => reject(abortError()))
		}))

		const pending = fetchJsonWithTimeout('/x')
		const assertion = expect(pending).rejects.toThrow('Request timed out.')
		await vi.advanceTimersByTimeAsync(11_000)
		await assertion
	})

	// The three cases the chaining exists for.
	it('does not send a request when the caller signal is already aborted', async () => {
		const controller = new AbortController()
		controller.abort()
		const fetchMock = stubFetch((url, init) => (init.signal.aborted
			? Promise.reject(abortError())
			: Promise.resolve(jsonResponse({}))))

		await expect(fetchJsonWithTimeout('/x', { signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' })
		expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true)
	})

	it('keeps a caller abort an AbortError rather than rewriting it as a timeout', async () => {
		const controller = new AbortController()
		stubFetch((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => reject(abortError()))
		}))

		const pending = fetchJsonWithTimeout('/x', { signal: controller.signal })
		controller.abort()

		await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
	})

	it('removes its listener from the caller signal when the request settles', async () => {
		const controller = new AbortController()
		const removeSpy = vi.spyOn(controller.signal, 'removeEventListener')
		stubFetch(jsonResponse({ ok: true }))

		await fetchJsonWithTimeout('/x', { signal: controller.signal })

		expect(removeSpy).toHaveBeenCalledWith('abort', expect.any(Function))
		// And a later abort no longer reaches anything this call created.
		controller.abort()
	})
})
