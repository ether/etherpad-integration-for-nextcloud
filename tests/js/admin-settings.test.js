/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MODULE = '../../src/admin-settings.js'

const setupAdminDom = () => {
	document.body.innerHTML = `
		<div id="etherpad-nextcloud-admin-settings"
			data-save-url="/save"
			data-health-url="/health"
			data-consistency-url="/consistency"
			data-retry-pending-url="/retry"
			data-l10n-saving="Saving..."
			data-l10n-saved="Saved."
			data-l10n-checking="Checking..."
			data-l10n-health-ok="Connection ok.">
			<form id="etherpad-nextcloud-admin-form">
				<input name="etherpad_host" value="https://pad.example.org">
				<input name="etherpad_api_host" value="">
				<input name="etherpad_cookie_domain" value="">
				<input name="etherpad_api_key" value="">
				<input name="sync_interval_seconds" value="120">
				<input type="checkbox" name="enable_protected_pads" checked>
				<input type="checkbox" name="enable_public_pads" checked>
				<p id="pad-types-none-hint" class="ep-field-hint" role="status" data-message="No pad type is enabled."></p>
				<input type="checkbox" name="delete_on_trash" checked>
				<input type="checkbox" name="allow_external_pads">
				<textarea name="external_pad_allowlist"></textarea>
				<textarea name="trusted_embed_origins"></textarea>
				<button type="submit">Save</button>
				<p id="etherpad-nextcloud-admin-status" class="ep-status"></p>
				<button type="button" id="etherpad-nextcloud-health-check">Test</button>
				<button type="button" id="etherpad-nextcloud-consistency-check">Check</button>
				<p id="etherpad-nextcloud-connection-status" class="ep-status"></p>
				<p id="etherpad-nextcloud-diagnostics-status" class="ep-status"></p>
				<p id="epnc-cookie-warning"></p>
			</form>
		</div>
	`
}

const saveStatus = () => document.getElementById('etherpad-nextcloud-admin-status')
const diagnosticsStatus = () => document.getElementById('etherpad-nextcloud-diagnostics-status')
const connectionStatus = () => document.getElementById('etherpad-nextcloud-connection-status')

/** The client only treats a body carrying `ok: true` as success. */
const okResponse = (body) => ({
	ok: true,
	status: 200,
	text: () => Promise.resolve(JSON.stringify({ ok: true, ...body })),
})

/** A response the test resolves itself, to complete overlapping requests
 * in a deliberately reversed order. */
const deferred = () => {
	let resolve
	const promise = new Promise((r) => {
		resolve = r
	})
	return {
		promise,
		respond: (body) => resolve(okResponse(body)),
	}
}

const flush = async () => {
	for (let i = 0; i < 8; i += 1) {
		await Promise.resolve()
	}
}

describe('admin settings status areas', () => {
	beforeEach(async () => {
		vi.resetModules()
		global.OC = { requestToken: 'token' }
		setupAdminDom()
	})

	afterEach(() => {
		document.body.innerHTML = ''
		delete global.OC
		vi.unstubAllGlobals()
	})

	it('reports saving and diagnostics next to their own actions', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'Connection ok.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(connectionStatus().textContent).toContain('Connection ok.')
		// Success, not the error path.
		expect(connectionStatus().classList.contains('ep-status-success')).toBe(true)
		expect(saveStatus().textContent).toBe('')
		expect(diagnosticsStatus().textContent).toBe('')
	})

	it('keeps a diagnostics result when a later save response arrives', async () => {
		const health = deferred()
		const save = deferred()
		vi.stubGlobal('fetch', vi.fn((url) => (url === '/health' ? health.promise : save.promise)))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()
		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		// Diagnostics finishes first, the save afterwards.
		health.respond({ message: 'Connection ok.' })
		await flush()
		save.respond({ message: 'Saved.' })
		await flush()

		expect(connectionStatus().textContent).toContain('Connection ok.')
		expect(connectionStatus().classList.contains('ep-status-success')).toBe(true)
		expect(saveStatus().textContent).toContain('Saved.')
		expect(saveStatus().classList.contains('ep-status-success')).toBe(true)
	})

	it('clears the other area when a new action starts', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'Connection ok.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()
		expect(connectionStatus().textContent).toContain('Connection ok.')

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(connectionStatus().textContent).toBe('')
		expect(connectionStatus().classList.contains('ep-status-success')).toBe(false)
	})

	it('falls back to the diagnostics area when the connection area is missing', async () => {
		connectionStatus().remove()
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'Connection ok.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(diagnosticsStatus().textContent).toContain('Connection ok.')
		expect(diagnosticsStatus().classList.contains('ep-status-success')).toBe(true)
	})

	it('falls back all the way to the save area when neither exists', async () => {
		connectionStatus().remove()
		diagnosticsStatus().remove()
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'Connection ok.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(saveStatus().textContent).toContain('Connection ok.')
	})
})

describe('protected pads cookie warning', () => {
	const warning = () => document.getElementById('epnc-cookie-warning')
	const problem = (message) => ({ ok: false, status: 'warning', reason: 'no_common_parent', message })

	beforeEach(() => {
		vi.resetModules()
		global.OC = { requestToken: 'token' }
		setupAdminDom()
	})

	afterEach(() => {
		document.body.innerHTML = ''
		delete global.OC
		vi.unstubAllGlobals()
	})

	it('shows the warning reported by the connection test', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Connection ok.',
			protected_pads: problem('Hosts do not share a parent domain.'),
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(warning().textContent).toBe('Hosts do not share a parent domain.')
	})

	it('refreshes the warning when settings are saved', async () => {
		warning().textContent = 'Hosts do not share a parent domain.'
		// The save fixed the domain, so the stale warning has to go without a
		// reload or a separate connection test.
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Saved.',
			protected_pads: { ok: true, status: 'ok', reason: 'common_parent', message: '' },
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(warning().textContent).toBe('')
	})

	it('raises a new warning on save when the domain was broken', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Saved.',
			protected_pads: problem('Hosts do not share a parent domain.'),
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(warning().textContent).toBe('Hosts do not share a parent domain.')
	})

	it('clears the warning when protected pads are switched off', async () => {
		warning().textContent = 'Hosts do not share a parent domain.'
		// null means the check did not run, not that a problem persists.
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'Saved.', protected_pads: null }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(warning().textContent).toBe('')
	})
})

describe('pad types live region', () => {
	const hint = () => document.getElementById('pad-types-none-hint')
	const protectedBox = () => document.querySelector('[name="enable_protected_pads"]')
	const publicBox = () => document.querySelector('[name="enable_public_pads"]')

	const uncheck = (box) => {
		box.checked = false
		box.dispatchEvent(new Event('change', { bubbles: true }))
	}

	beforeEach(() => {
		vi.resetModules()
		global.OC = { requestToken: 'token' }
		setupAdminDom()
		vi.stubGlobal('fetch', vi.fn())
	})

	afterEach(() => {
		document.body.innerHTML = ''
		delete global.OC
		vi.unstubAllGlobals()
	})

	it('writes the message as text so the live region announces it', async () => {
		await import(MODULE)
		expect(hint().textContent).toBe('')

		uncheck(protectedBox())
		uncheck(publicBox())

		expect(hint().textContent).toBe('No pad type is enabled.')
		expect(hint().style.display).toBe('')
	})

	it('clears the text again once a pad type is re-enabled', async () => {
		await import(MODULE)
		uncheck(protectedBox())
		uncheck(publicBox())
		expect(hint().textContent).not.toBe('')

		publicBox().checked = true
		publicBox().dispatchEvent(new Event('change', { bubbles: true }))

		expect(hint().textContent).toBe('')
	})

	it('leaves already-rendered text untouched on load', async () => {
		// Server-rendered state: a rewrite would announce it as if the admin
		// had just changed something.
		hint().textContent = 'No pad type is enabled.'
		protectedBox().checked = false
		publicBox().checked = false
		const observed = []
		new MutationObserver((records) => observed.push(...records))
			.observe(hint(), { childList: true, characterData: true, subtree: true })

		await import(MODULE)
		await flush()

		expect(hint().textContent).toBe('No pad type is enabled.')
		expect(observed).toHaveLength(0)
	})
})
