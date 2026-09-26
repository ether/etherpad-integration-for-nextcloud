/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushAsyncWork } from './flush.js'



const setupEmbedCreateDom = () => {
	document.body.innerHTML = `
		<div id="etherpad-nextcloud-embed-create"
			class="epnc-embed"
			data-parent-folder-id="42"
			data-create-by-parent-url="/api/create-by-parent"
			data-request-token="csrf"
			data-l10n-missing-name="Pad name is required."
			data-l10n-invalid-access-mode="Invalid access mode."
			data-l10n-incomplete-config="Embed configuration is incomplete."
			data-l10n-unanswered="No answer; look in the folder first.">
			<div data-epnc-embed-create-loading>loading</div>
			<div data-epnc-embed-create-error hidden>
				<p data-epnc-embed-create-error-message></p>
			</div>
		</div>
	`
}

const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

const errorResponse = (body, status = 400) => jsonResponse(body, false, status)

/** A proxy's or a maintenance page in place of this app's answer. */
const pageResponse = (status) => ({
	ok: false,
	status,
	json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
})

const errorMessageText = () => document.querySelector('[data-epnc-embed-create-error-message]').textContent
const errorPanelHidden = () => document.querySelector('[data-epnc-embed-create-error]').hidden

let parentPostSpy
let locationReplaceSpy
let originalLocationDescriptor

const importEmbedCreate = async (search) => {
	const url = `http://localhost/embed/create-by-parent/42${search ?? '?name=My%20Pad&accessMode=protected'}`
	// happy-dom doesn't let us simply reassign window.location; redefine just
	// the bits we touch. `configurable: true` is required so afterEach can
	// restore the original descriptor and the next test can redefine again.
	Object.defineProperty(window, 'location', {
		configurable: true,
		writable: true,
		value: {
			href: url,
			origin: 'http://localhost',
			pathname: '/embed/create-by-parent/42',
			search: search ?? '?name=My%20Pad&accessMode=protected',
			replace: locationReplaceSpy,
		},
	})
	vi.resetModules()
	await import('../../src/embed-create-main.js')
}

beforeEach(() => {
	setupEmbedCreateDom()
	window.OC = { requestToken: 'csrf' }
	globalThis.fetch = vi.fn()
	// Pretend we're embedded inside another window so window.parent !== window.
	parentPostSpy = vi.fn()
	Object.defineProperty(window, 'parent', {
		configurable: true,
		value: { postMessage: parentPostSpy },
	})
	locationReplaceSpy = vi.fn()
	// Cache the original `location` descriptor so afterEach can restore it
	// instead of leaving the test's mock around for the next file.
	originalLocationDescriptor = Object.getOwnPropertyDescriptor(window, 'location')
})

afterEach(() => {
	document.body.innerHTML = ''
	delete window.OC
	delete globalThis.fetch
	// Restore parent (point back at window so happy-dom's defaults hold).
	Object.defineProperty(window, 'parent', { configurable: true, value: window })
	// Restore the location descriptor we cached in beforeEach (if any).
	if (originalLocationDescriptor) {
		Object.defineProperty(window, 'location', originalLocationDescriptor)
	}
})

describe('embed-create-main', () => {
	it('posts epnc:create-succeeded and redirects on a clean create', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({
			embed_url: '/embed/by-id/777',
			file_id: 777,
			pad_id: 'g.abc$mypad',
			access_mode: 'protected',
		}))

		await importEmbedCreate()
		await flushAsyncWork()

		expect(fetch).toHaveBeenCalledOnce()
		expect(fetch.mock.calls[0][0]).toBe('/api/create-by-parent')

		// Host gets a structured success event with the new pad's identity.
		expect(parentPostSpy).toHaveBeenCalledOnce()
		const [payload, targetOrigin] = parentPostSpy.mock.calls[0]
		expect(payload).toEqual({
			type: 'epnc:create-succeeded',
			embed_url: '/embed/by-id/777',
			file_id: 777,
			pad_id: 'g.abc$mypad',
			access_mode: 'protected',
		})
		expect(targetOrigin).toBe('*')

		// And the iframe redirects to the embed open URL.
		expect(locationReplaceSpy).toHaveBeenCalledWith('/embed/by-id/777')
	})

	/** The other mode reaches the server too, not only the default one. */
	it('sends a public pad through as readily as a protected one', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({
			embed_url: '/embed/by-id/778',
			file_id: 778,
			pad_id: 'nc-abc',
			access_mode: 'public',
		}))

		await importEmbedCreate('?name=Test&accessMode=public')
		await flushAsyncWork()

		expect(fetch).toHaveBeenCalledOnce()
		expect(String(fetch.mock.calls[0][1].body)).toContain('accessMode=public')

		// The mode has to survive the round trip, not only the request.
		expect(parentPostSpy.mock.calls[0][0]).toEqual({
			type: 'epnc:create-succeeded',
			embed_url: '/embed/by-id/778',
			file_id: 778,
			pad_id: 'nc-abc',
			access_mode: 'public',
		})
		expect(locationReplaceSpy).toHaveBeenCalledWith('/embed/by-id/778')
	})

	it('posts epnc:create-failed with reason=conflict on a 409 from the API', async () => {
		fetch.mockResolvedValueOnce(errorResponse(
			{ message: 'A file with this name already exists.' },
			409,
		))

		await importEmbedCreate()
		await flushAsyncWork()

		expect(parentPostSpy).toHaveBeenCalledOnce()
		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('conflict')
		expect(payload.status).toBe(409)
		expect(payload.message).toBe('A file with this name already exists.')

		// Inline error stays visible for users who can actually see the iframe.
		expect(errorPanelHidden()).toBe(false)
		expect(errorMessageText()).toBe('A file with this name already exists.')

		// No redirect on failure.
		expect(locationReplaceSpy).not.toHaveBeenCalled()
	})

	it('posts epnc:create-failed with reason=server on a 5xx response', async () => {
		fetch.mockResolvedValueOnce(errorResponse(
			{ message: 'Could not create pad' },
			500,
		))

		await importEmbedCreate()
		await flushAsyncWork()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('server')
		expect(payload.status).toBe(500)
	})

	it('posts epnc:create-failed with reason=network when fetch itself throws', async () => {
		fetch.mockRejectedValueOnce(new Error('Network unreachable'))

		await importEmbedCreate()
		await flushAsyncWork()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('network')
		expect(payload.status).toBe(null)
		expect(payload.message).toBe('Network unreachable')
	})

	// No answer, so no knowing whether the pad was made: the page says so.
	it('says the pad may exist when no answer came', async () => {
		fetch.mockRejectedValueOnce(new TypeError('Failed to fetch'))

		await importEmbedCreate()
		await flushAsyncWork()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload).toEqual({ type: 'epnc:create-failed', reason: 'network', status: null, message: 'No answer; look in the folder first.' })
		expect(errorMessageText()).toBe('No answer; look in the folder first.')
	})

	/**
	 * A proxy answering in this app's place is no answer from it either,
	 * whatever it sends; its status goes along. This app's own 503 is an
	 * answer: nothing was created.
	 */
	it.each([
		['a proxy whose backend is gone', pageResponse(502), 'network', 502],
		['a proxy that gave up waiting', pageResponse(504), 'network', 504],
		['a gateway answering JSON of its own', errorResponse({ message: 'An invalid response was received from the upstream server' }, 503), 'network', 503],
		['this app, the folder locked', errorResponse({ message: 'Pad file is temporarily locked. Please retry.', retryable: true }, 503), 'server', 503],
	])('tells the host whether %s answered', async (_, response, reason, status) => {
		fetch.mockResolvedValueOnce(response)

		await importEmbedCreate()
		await flushAsyncWork()

		expect(parentPostSpy.mock.calls[0][0]).toMatchObject({ type: 'epnc:create-failed', reason, status })
	})

	// A write: cut short, it would go on creating with nobody told.
	it('waits for a slow create instead of calling it failed', async () => {
		vi.useFakeTimers()
		try {
			let settle
			fetch.mockImplementationOnce((url, init) => new Promise((resolve, reject) => {
				init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
				settle = () => resolve(jsonResponse({ embed_url: '/embed/by-id/777', file_id: 777, pad_id: 'g.abc$mypad', access_mode: 'protected' }))
			}))

			await importEmbedCreate()
			await vi.advanceTimersByTimeAsync(60_000)
			expect(parentPostSpy).not.toHaveBeenCalled()
			settle()
			await flushAsyncWork()

			expect(parentPostSpy).toHaveBeenCalledOnce()
			expect(parentPostSpy.mock.calls[0][0].type).toBe('epnc:create-succeeded')
			expect(locationReplaceSpy).toHaveBeenCalledWith('/embed/by-id/777')
		} finally {
			vi.useRealTimers()
		}
	})

	it('posts epnc:create-failed with reason=invalid when launcher params are missing', async () => {
		await importEmbedCreate('?accessMode=protected') // no name param

		await flushAsyncWork()

		expect(fetch).not.toHaveBeenCalled()
		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('invalid')
		expect(payload.message).toBe('Pad name is required.')
	})

	/** Refused here, not by the server, so the host gets `invalid` not `server`. */
	it('posts epnc:create-failed with reason=invalid for an access mode that is not one', async () => {
		await importEmbedCreate('?name=Test&accessMode=external')

		await flushAsyncWork()

		expect(fetch).not.toHaveBeenCalled()
		expect(parentPostSpy).toHaveBeenCalledTimes(1)
		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('invalid')
		expect(payload.message).toBe('Invalid access mode.')
	})

	it('emits epnc:create-failed with reason=invalid when embed config is incomplete', async () => {
		// Simulates the host page mounting the iframe without the
		// data-create-by-parent-url attribute (or with a non-numeric
		// parent-folder-id). The flow should bail before doing any fetch
		// AND still send the host a structured signal.
		document.body.innerHTML = `
			<div id="etherpad-nextcloud-embed-create"
				class="epnc-embed"
				data-parent-folder-id=""
				data-create-by-parent-url=""
				data-request-token="csrf"
				data-l10n-missing-name="Pad name is required."
				data-l10n-invalid-access-mode="Invalid access mode."
				data-l10n-incomplete-config="Embed configuration is incomplete.">
				<div data-epnc-embed-create-loading>loading</div>
				<div data-epnc-embed-create-error hidden>
					<p data-epnc-embed-create-error-message></p>
				</div>
			</div>
		`
		await importEmbedCreate()
		await flushAsyncWork()

		expect(fetch).not.toHaveBeenCalled()
		expect(locationReplaceSpy).not.toHaveBeenCalled()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('invalid')
		expect(payload.status).toBe(null)
		expect(payload.message).toBe('Embed configuration is incomplete.')
	})

	it('emits epnc:create-failed with reason=invalid when the CSRF token is missing', async () => {
		// data-request-token empty AND no window.OC fallback. The script
		// should refuse to call fetch and surface a structured invalid
		// signal to the host.
		document.body.innerHTML = `
			<div id="etherpad-nextcloud-embed-create"
				class="epnc-embed"
				data-parent-folder-id="42"
				data-create-by-parent-url="/api/create-by-parent"
				data-request-token=""
				data-l10n-missing-name="Pad name is required."
				data-l10n-invalid-access-mode="Invalid access mode."
				data-l10n-incomplete-config="Embed configuration is incomplete.">
				<div data-epnc-embed-create-loading>loading</div>
				<div data-epnc-embed-create-error hidden>
					<p data-epnc-embed-create-error-message></p>
				</div>
			</div>
		`
		delete window.OC
		await importEmbedCreate()
		await flushAsyncWork()

		expect(fetch).not.toHaveBeenCalled()
		expect(locationReplaceSpy).not.toHaveBeenCalled()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('invalid')
		expect(payload.message).toBe('CSRF request token is missing.')
	})

	it('does not emit succeeded then failed when the server returns a cross-origin embed_url', async () => {
		// Regression: an earlier version emitted `epnc:create-succeeded`
		// before validating the redirect target. A bad embed_url would then
		// throw on `normalizeEmbedRedirectUrl()`, fall into the catch, and
		// emit a contradictory `epnc:create-failed` — leaving the host with
		// both signals for the same operation. We now validate first.
		fetch.mockResolvedValueOnce(jsonResponse({
			embed_url: 'https://evil.example/whatever',
			file_id: 777,
			pad_id: 'p',
			access_mode: 'protected',
		}))

		await importEmbedCreate()
		await flushAsyncWork()

		// Exactly one event, classified as a server-side response problem
		// (not a network error — fetch itself succeeded).
		expect(parentPostSpy).toHaveBeenCalledOnce()
		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('server')
		// And no redirect happened.
		expect(locationReplaceSpy).not.toHaveBeenCalled()
	})

	it('does not postMessage when not embedded (window.parent === window)', async () => {
		// Restore parent === window for this test only.
		Object.defineProperty(window, 'parent', { configurable: true, value: window })
		fetch.mockResolvedValueOnce(jsonResponse({
			embed_url: '/embed/by-id/777',
			file_id: 777,
			pad_id: 'p',
			access_mode: 'protected',
		}))

		await importEmbedCreate()
		await flushAsyncWork()

		expect(parentPostSpy).not.toHaveBeenCalled()
		expect(locationReplaceSpy).toHaveBeenCalled()
	})
})
