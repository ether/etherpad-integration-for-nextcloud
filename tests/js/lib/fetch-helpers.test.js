/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchJsonWithTimeout, isUnanswered, requestErrorMessage } from '../../../src/lib/fetch-helpers.js'
import { MISSING_BINDING, UNREACHABLE } from '../answers.js'

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

/** An answer whose body is not JSON: a proxy's or Nextcloud's own page. */
const pageResponse = (status) => ({
	ok: status < 400,
	status,
	json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
})

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
		stubFetch(jsonResponse(MISSING_BINDING, false, 400))

		await expect(fetchJsonWithTimeout('/x')).rejects.toMatchObject({
			message: 'no binding',
			status: 400,
			code: 'missing_binding',
		})
	})

	// The server's word that the same request may succeed later, carried
	// as it is; anything but true is no such word.
	it.each([
		['says so', UNREACHABLE, 503, true],
		['says nothing', { message: 'Request failed.' }, 500, undefined],
		['says something else', { message: 'no binding', retryable: 'yes' }, 400, undefined],
	])('carries retryable out of an error response that %s', async (_, body, status, retryable) => {
		stubFetch(jsonResponse(body, false, status))

		const error = await fetchJsonWithTimeout('/x').catch((e) => e)

		expect(error.retryable).toBe(retryable)
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
		const error = pending.catch((e) => e)
		await vi.advanceTimersByTimeAsync(11_000)

		// No answer, which is not the server's word that trying again helps.
		expect(await error).toMatchObject({ message: 'Request timed out.', unanswered: true })
		expect((await error).retryable).toBeUndefined()
	})

	it('marks a failed network as no answer', async () => {
		stubFetch(() => Promise.reject(new TypeError('Failed to fetch')))

		const error = await fetchJsonWithTimeout('/x').catch((e) => e)

		expect(error).toMatchObject({ name: 'TypeError', unanswered: true })
		expect(error.retryable).toBeUndefined()
	})

	// The headers came, the body did not: still no answer, not an empty one.
	it('takes a timeout while the body streams in for no answer', async () => {
		vi.useFakeTimers()
		stubFetch((url, init) => Promise.resolve({
			ok: true,
			status: 200,
			json: () => new Promise((resolve, reject) => {
				init.signal.addEventListener('abort', () => reject(abortError()))
			}),
		}))

		const error = fetchJsonWithTimeout('/x').catch((e) => e)
		await vi.advanceTimersByTimeAsync(11_000)

		// The status came before the body broke off, and goes along.
		expect(await error).toMatchObject({ message: 'Request timed out.', unanswered: true, status: 200 })
	})

	it('takes a network failure while the body streams in for no answer, with the status that came', async () => {
		stubFetch({ ok: false, status: 409, json: () => Promise.reject(new TypeError('network error')) })

		await expect(fetchJsonWithTimeout('/x')).rejects.toMatchObject({ name: 'TypeError', unanswered: true, status: 409 })
	})

	it('has no status to give when fetch itself failed', async () => {
		stubFetch(() => Promise.reject(new TypeError('Failed to fetch')))

		const error = await fetchJsonWithTimeout('/x').catch((e) => e)

		expect(error.status).toBeUndefined()
	})

	it('reads a body that is not JSON as an empty one', async () => {
		stubFetch(pageResponse(200))

		await expect(fetchJsonWithTimeout('/x')).resolves.toEqual({})
	})

	// This app answers every error in JSON, never 502 or 504, and its every
	// 503 is retryable. A 5xx that is no such answer came from a proxy, a
	// maintenance page or PHP dying midway, whatever it sends - a gateway's
	// JSON may carry a message too. A 4xx page never reached the create.
	it.each([
		['a proxy whose backend is gone', pageResponse(502), true],
		['Nextcloud in maintenance', pageResponse(503), true],
		['a proxy that gave up waiting', pageResponse(504), true],
		['a gateway answering in JSON of its own', jsonResponse({ error: 'Bad Gateway' }, false, 502), true],
		['a gateway answering JSON with a message', jsonResponse({ message: 'An invalid response was received from the upstream server' }, false, 502), true],
		['a gateway timing out with a message', jsonResponse({ message: 'Endpoint request timed out' }, false, 504), true],
		['a load balancer out of peers', jsonResponse({ message: 'failure to get a peer from the ring-balancer' }, false, 503), true],
		['a gateway answering JSON null', jsonResponse(null, false, 503), true],
		['this app, not reachable further on', jsonResponse(UNREACHABLE, false, 503), undefined],
		['PHP dying midway', pageResponse(500), true],
		['a proxy that gave up on a slow origin', pageResponse(524), true],
		['this app failing', jsonResponse({ message: 'Request failed.' }, false, 500), undefined],
		['a proxy refusing a body too large', pageResponse(413), undefined],
	])('tells whether %s answered', async (_, response, unanswered) => {
		stubFetch(response)

		const error = await fetchJsonWithTimeout('/x').catch((e) => e)

		expect(error.unanswered).toBe(unanswered)
		// The answer's own error, not one from reading it.
		expect(error.status).toBe(response.status)
	})

	// Writes wait. Cutting one short applies the change with nobody left to
	// read the outcome; the retry then meets what the first run wrote.
	it('never times out when the timeout is disabled', async () => {
		vi.useFakeTimers()
		let settle
		// Rejects on abort, the way fetch does — otherwise the timer firing
		// would make no difference to this test and it would pass with or
		// without the exemption.
		stubFetch((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => reject(abortError()))
			settle = () => resolve(jsonResponse({ status: 'restored' }))
		}))

		const pending = fetchJsonWithTimeout('/x', { method: 'POST' }, { timeoutMs: null })
		await vi.advanceTimersByTimeAsync(120_000)
		settle()

		await expect(pending).resolves.toEqual({ status: 'restored' })
	})

	it('still lets a caller abort a request that has no timeout', async () => {
		const controller = new AbortController()
		stubFetch((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => reject(abortError()))
		}))

		const pending = fetchJsonWithTimeout('/x', { signal: controller.signal }, { timeoutMs: null })
		controller.abort()

		await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
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

		const error = await pending.catch((e) => e)
		expect(error.name).toBe('AbortError')
		// The caller moved on; nobody is to be offered this again.
		expect(error.unanswered).toBeUndefined()
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

describe('requestErrorMessage', () => {
	const timedOut = Object.assign(new Error('Request timed out.'), { unanswered: true })

	it.each([
		['no answer came', timedOut, 'No answer.'],
		['the server said why', new Error('no binding'), 'no binding'],
		['nothing says why', new Error(''), 'Failed.'],
		['what failed is no error', 'boom', 'Failed.'],
	])('says what went wrong when %s', (_, error, expected) => {
		expect(requestErrorMessage(error, 'No answer.', 'Failed.')).toBe(expected)
	})
})

describe('isUnanswered', () => {
	it.each([
		['nothing came back', Object.assign(new Error('Request timed out.'), { unanswered: true }), true],
		['this app answered', Object.assign(new Error('no binding'), { status: 400 }), false],
		['the flag is not quite true', Object.assign(new Error('x'), { unanswered: 'yes' }), false],
		['there is no error', null, false],
	])('when %s', (_, error, expected) => {
		expect(isUnanswered(error)).toBe(expected)
	})
})
