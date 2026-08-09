/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const flushMicrotasks = async () => {
	for (let i = 0; i < 8; i += 1) {
		await Promise.resolve()
	}
}

const setupEmbedCreateDom = () => {
	document.body.innerHTML = `
		<div id="etherpad-nextcloud-embed-create"
			class="epnc-embed"
			data-parent-folder-id="42"
			data-create-by-parent-url="/api/create-by-parent"
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
}

const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

const errorResponse = (body, status = 400) => jsonResponse(body, false, status)

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
		await flushMicrotasks()

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

	it('posts epnc:create-failed with reason=conflict on a 409 from the API', async () => {
		fetch.mockResolvedValueOnce(errorResponse(
			{ message: 'A file with this name already exists.' },
			409,
		))

		await importEmbedCreate()
		await flushMicrotasks()

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
		await flushMicrotasks()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('server')
		expect(payload.status).toBe(500)
	})

	it('posts epnc:create-failed with reason=network when fetch itself throws', async () => {
		fetch.mockRejectedValueOnce(new Error('Network unreachable'))

		await importEmbedCreate()
		await flushMicrotasks()

		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('network')
		expect(payload.status).toBe(null)
		expect(payload.message).toBe('Network unreachable')
	})

	it('posts epnc:create-failed with reason=invalid when launcher params are missing', async () => {
		await importEmbedCreate('?accessMode=protected') // no name param

		await flushMicrotasks()

		expect(fetch).not.toHaveBeenCalled()
		const payload = parentPostSpy.mock.calls[0][0]
		expect(payload.type).toBe('epnc:create-failed')
		expect(payload.reason).toBe('invalid')
		expect(payload.message).toBe('Pad name is required.')
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
		await flushMicrotasks()

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
		await flushMicrotasks()

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
		await flushMicrotasks()

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
		await flushMicrotasks()

		expect(parentPostSpy).not.toHaveBeenCalled()
		expect(locationReplaceSpy).toHaveBeenCalled()
	})
})
