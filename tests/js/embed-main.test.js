/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/lib/pad-content.js', () => ({
	loadPadContent: vi.fn(async () => ({ html: '', isEmpty: true })),
}))

const { loadPadContent } = await import('../../src/lib/pad-content.js')

const flushMicrotasks = async () => {
	for (let i = 0; i < 8; i += 1) {
		await Promise.resolve()
	}
}

const setupEmbedDom = () => {
	document.body.innerHTML = `
		<div id="etherpad-nextcloud-embed"
			data-file-id="42"
			data-open-by-id-url="/api/open-by-id"
			data-initialize-by-id-url-template="/api/init/__FILE_ID__"
			data-recover-url-template="/api/recover/__FILE_ID__"
			data-find-original-url-template="/api/find-original/__FILE_ID__"
			data-request-token="csrf"
			data-trusted-origins="https://host.example">
			<div data-epnc-embed-loading>loading</div>
			<div data-epnc-embed-error hidden>
				<p data-epnc-embed-error-message></p>
			</div>
			<div data-epnc-embed-recovery hidden>
				<p data-epnc-embed-recovery-message></p>
				<p data-epnc-embed-recovery-body></p>
				<div data-epnc-embed-recovery-actions></div>
			</div>
			<iframe data-epnc-embed-iframe hidden></iframe>
		</div>
	`
}

const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

const errorResponse = (body, status = 400) => jsonResponse(body, false, status)

const root = () => document.getElementById('etherpad-nextcloud-embed')
const errorMessage = () => document.querySelector('[data-epnc-embed-error-message]').textContent
const recoveryMessage = () => document.querySelector('[data-epnc-embed-recovery-message]').textContent
const recoveryBody = () => document.querySelector('[data-epnc-embed-recovery-body]').textContent
const recoveryActions = () => document.querySelector('[data-epnc-embed-recovery-actions]')
const iframe = () => document.querySelector('[data-epnc-embed-iframe]')

const isHidden = (selector) => {
	const el = document.querySelector(selector)
	return el === null || el.hidden === true
}

const importEmbed = async () => {
	vi.resetModules()
	await import('../../src/embed-main.js')
}

beforeEach(() => {
	setupEmbedDom()
	window.OC = { requestToken: 'csrf' }
	globalThis.fetch = vi.fn()
	loadPadContent.mockReset()
	loadPadContent.mockResolvedValue({ html: '', isEmpty: true })
})

afterEach(() => {
	// happy-dom tries to fetch the iframe.src on disconnect, which throws
	// AbortError noise into the test output. Strip the src first.
	const frame = document.querySelector('[data-epnc-embed-iframe]')
	if (frame) frame.removeAttribute('src')
	document.body.innerHTML = ''
	delete window.OC
	delete globalThis.fetch
})

describe('embed-main', () => {
	it('renders the iframe with the returned pad URL on a clean open', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({
			url: 'https://pad.example.test/p/abc',
			sync_url: '/api/sync/42',
			sync_interval_seconds: 60,
		}))

		await importEmbed()
		await flushMicrotasks()

		expect(fetch).toHaveBeenCalledOnce()
		expect(fetch.mock.calls[0][0]).toBe('/api/open-by-id')
		expect(iframe().src).toContain('https://pad.example.test/p/abc')
		// Error and recovery panels stay hidden.
		expect(isHidden('[data-epnc-embed-error]')).toBe(true)
		expect(isHidden('[data-epnc-embed-recovery]')).toBe(true)
	})

	it('renders the external pad view when is_external is set', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({
			url: 'https://pad.remote.test/p/foreign',
			is_external: true,
			content_url: '/api/content/42',
		}))
		loadPadContent.mockResolvedValueOnce({ html: '<p>remote body</p>', isEmpty: false })

		await importEmbed()
		await flushMicrotasks()

		// Iframe stays hidden — external pads render our own view instead.
		expect(iframe().hidden).toBe(true)
		expect(loadPadContent).toHaveBeenCalledWith('/api/content/42')
		expect(document.body.textContent).toContain('remote body')
		// The "Open original pad" link points at the remote URL.
		const link = document.querySelector('a.epnc-pad-doc__link')
		expect(link).not.toBeNull()
		expect(link.href).toContain('https://pad.remote.test/p/foreign')
		expect(link.target).toBe('_blank')
	})

	it('renders the read-only view for a share without write permission', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({
			url: '',
			is_readonly_view: true,
			content_url: '/api/content/42',
			sync_url: '',
		}))
		loadPadContent.mockResolvedValueOnce({ html: '<p>shared body</p>', isEmpty: false })

		await importEmbed()
		await flushMicrotasks()

		// Accepted at all: the payload carries no url, which the open used
		// to reject outright and answer with the error card.
		expect(document.body.textContent).toContain('shared body')
		expect(iframe().hidden).toBe(true)
		// Nothing to click through to: the pad the link would point at is
		// the one being withheld.
		expect(document.querySelector('a.epnc-pad-doc__link')).toBeNull()
	})

	/**
	 * Available whatever the state, not only after a failure — the view
	 * shows the pad as of the last fetch, and an embed has no reload of
	 * its own the reader can reach.
	 */
	it('refreshes on demand without re-running the open', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ url: '', is_readonly_view: true, content_url: '/api/content/42', sync_url: '' }))
		loadPadContent.mockResolvedValueOnce({ html: '<p>first</p>', isEmpty: false })

		await importEmbed()
		await flushMicrotasks()
		expect(document.body.textContent).toContain('first')

		const refresh = document.querySelector('button.epnc-pad-doc__refresh')
		expect(refresh).not.toBeNull()
		expect(refresh.disabled).toBe(false)

		loadPadContent.mockResolvedValueOnce({ html: '<p>second</p>', isEmpty: false })
		refresh.click()
		await flushMicrotasks()

		expect(loadPadContent).toHaveBeenCalledTimes(2)
		expect(document.body.textContent).toContain('second')
		// One open, and no second one: refreshing asks the content endpoint
		// and nothing else.
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(refresh.disabled).toBe(false)
	})

	/**
	 * The pad stays on screen while the next answer is on its way — a
	 * refresh that blanks the view first is a flash of nothing on every
	 * press.
	 */
	it('keeps the last content visible while refreshing, and says so on the button', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ url: '', is_readonly_view: true, content_url: '/api/content/42', sync_url: '' }))
		loadPadContent.mockResolvedValueOnce({ html: '<p>first</p>', isEmpty: false })

		await importEmbed()
		await flushMicrotasks()

		let release
		loadPadContent.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve({ html: '<p>second</p>', isEmpty: false }) }))
		const refresh = document.querySelector('button.epnc-pad-doc__refresh')
		refresh.click()
		await flushMicrotasks()

		expect(document.body.textContent).toContain('first')
		expect(document.body.textContent).not.toContain('Loading pad content...')
		expect(refresh.disabled).toBe(true)
		expect(refresh.textContent).toBe('Refreshing...')

		release()
		await flushMicrotasks()

		expect(document.body.textContent).toContain('second')
		expect(refresh.textContent).toBe('Refresh')
	})

	/**
	 * A failed refresh must not take the pad away: what is on screen is
	 * still the last thing the pad said.
	 */
	it('keeps the content when a refresh fails and reports it beside the button', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ url: '', is_readonly_view: true, content_url: '/api/content/42', sync_url: '' }))
		loadPadContent.mockResolvedValueOnce({ html: '<p>first</p>', isEmpty: false })

		await importEmbed()
		await flushMicrotasks()

		loadPadContent.mockRejectedValueOnce(new Error('pad server unreachable'))
		document.querySelector('button.epnc-pad-doc__refresh').click()
		await flushMicrotasks()

		expect(document.body.textContent).toContain('first')
		const toolbarError = document.querySelector('.epnc-pad-doc__toolbar-error')
		expect(toolbarError.hidden).toBe(false)
		expect(toolbarError.textContent).toBe('pad server unreachable')
	})

	it('says so when the pad loaded and is empty', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ url: '', is_readonly_view: true, content_url: '/api/content/42', sync_url: '' }))
		loadPadContent.mockResolvedValueOnce({ html: '', isEmpty: true })

		await importEmbed()
		await flushMicrotasks()

		expect(document.body.textContent).toContain('This pad is still empty.')
	})

	/**
	 * The retry re-runs the fetch, which re-runs the access check on the
	 * server — so a reader whose access ended meanwhile is not served from
	 * anything kept in the page.
	 */
	it('offers a retry that loads again after a failure', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ url: '', is_readonly_view: true, content_url: '/api/content/42', sync_url: '' }))
		loadPadContent.mockRejectedValueOnce(new Error('pad server unreachable'))

		await importEmbed()
		await flushMicrotasks()

		expect(document.body.textContent).toContain('pad server unreachable')
		const retry = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Try again')
		expect(retry).toBeDefined()

		loadPadContent.mockResolvedValueOnce({ html: '<p>second try</p>', isEmpty: false })
		retry.click()
		await flushMicrotasks()

		expect(loadPadContent).toHaveBeenCalledTimes(2)
		expect(document.body.textContent).toContain('second try')
	})

	it('runs initialize + re-opens when the first open reports missing frontmatter', async () => {
		fetch
			.mockResolvedValueOnce(errorResponse({ message: 'Missing YAML frontmatter in .pad file.' }))
			.mockResolvedValueOnce(jsonResponse({ status: 'initialized', file_id: 42 }))
			.mockResolvedValueOnce(jsonResponse({ url: 'https://pad.example.test/p/abc' }))

		await importEmbed()
		await flushMicrotasks()

		expect(fetch).toHaveBeenCalledTimes(3)
		expect(fetch.mock.calls[0][0]).toBe('/api/open-by-id')
		expect(fetch.mock.calls[1][0]).toBe('/api/init/42')
		expect(fetch.mock.calls[2][0]).toBe('/api/open-by-id')
		expect(iframe().src).toContain('https://pad.example.test/p/abc')
	})

	it('renders the recovery card with the copy-detected body when find-original hits', async () => {
		fetch
			.mockResolvedValueOnce(errorResponse({ message: 'no binding', code: 'missing_binding' }))
			.mockResolvedValueOnce(jsonResponse({ found: true, embed_url: '/embed/by-id/99', viewer_url: '/files/99' }))

		await importEmbed()
		await flushMicrotasks()

		expect(isHidden('[data-epnc-embed-recovery]')).toBe(false)
		expect(recoveryMessage()).toBe('no binding')
		// The two action buttons land: the primary link to the original embed,
		// plus a secondary "Create new pad" button.
		const link = recoveryActions().querySelector('a')
		expect(link).not.toBeNull()
		expect(link.getAttribute('href')).toBe('/embed/by-id/99')
		const button = recoveryActions().querySelector('button')
		expect(button).not.toBeNull()
	})

	it('renders the orphan body when find-original misses', async () => {
		fetch
			.mockResolvedValueOnce(errorResponse({ message: 'no binding', code: 'missing_binding' }))
			.mockResolvedValueOnce(jsonResponse({ found: false }))

		await importEmbed()
		await flushMicrotasks()

		expect(isHidden('[data-epnc-embed-recovery]')).toBe(false)
		expect(recoveryMessage()).toBe('no binding')
		// No "open original" link, only the create button.
		expect(recoveryActions().querySelector('a')).toBeNull()
		expect(recoveryActions().querySelectorAll('button')).toHaveLength(1)
	})

	it('renders the orphan body when find-original throws (silently degrades)', async () => {
		fetch
			.mockResolvedValueOnce(errorResponse({ message: 'no binding', code: 'missing_binding' }))
			.mockRejectedValueOnce(new Error('lookup network error'))

		await importEmbed()
		await flushMicrotasks()

		expect(isHidden('[data-epnc-embed-recovery]')).toBe(false)
		// The recovery message stays the original missing-binding wording, not the lookup error.
		expect(recoveryMessage()).toBe('no binding')
		// Only the create-new button is rendered.
		expect(recoveryActions().querySelectorAll('button')).toHaveLength(1)
	})

	it('clicking the create-new button posts recover-from-snapshot and re-opens', async () => {
		fetch
			.mockResolvedValueOnce(errorResponse({ message: 'no binding', code: 'missing_binding' }))
			.mockResolvedValueOnce(jsonResponse({ found: false }))
			.mockResolvedValueOnce(jsonResponse({ status: 'restored', new_pad_id: 'fresh' }))
			.mockResolvedValueOnce(jsonResponse({ url: 'https://pad.example.test/p/fresh' }))

		await importEmbed()
		await flushMicrotasks()

		const button = recoveryActions().querySelector('button')
		button.click()
		await flushMicrotasks()

		// 1) open (400), 2) find-original (200 miss), 3) recover-from-snapshot, 4) open retry.
		expect(fetch).toHaveBeenCalledTimes(4)
		expect(fetch.mock.calls[2][0]).toBe('/api/recover/42')
		expect(fetch.mock.calls[3][0]).toBe('/api/open-by-id')
		expect(iframe().src).toContain('https://pad.example.test/p/fresh')
	})

	it('shows the error panel on a non-binding, non-frontmatter failure', async () => {
		fetch.mockResolvedValueOnce(errorResponse({ message: 'Internal server error' }, 500))

		await importEmbed()
		await flushMicrotasks()

		expect(isHidden('[data-epnc-embed-error]')).toBe(false)
		expect(errorMessage()).toBe('Internal server error')
		expect(isHidden('[data-epnc-embed-recovery]')).toBe(true)
	})

	it('refuses to run without a CSRF request token', async () => {
		root().setAttribute('data-request-token', '')
		delete window.OC

		await importEmbed()
		await flushMicrotasks()

		expect(fetch).not.toHaveBeenCalled()
		expect(isHidden('[data-epnc-embed-error]')).toBe(false)
		expect(errorMessage()).toContain('CSRF')
	})

	it('refuses to run when the embed config is incomplete', async () => {
		root().setAttribute('data-open-by-id-url', '')

		await importEmbed()
		await flushMicrotasks()

		expect(fetch).not.toHaveBeenCalled()
		expect(errorMessage()).toContain('configuration is incomplete')
	})
})
