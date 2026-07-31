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
				<input type="checkbox" name="delete_on_trash" checked>
				<input type="checkbox" name="allow_external_pads">
				<textarea name="external_pad_allowlist"></textarea>
				<textarea name="trusted_embed_origins"></textarea>
				<button type="submit">Save</button>
				<p id="etherpad-nextcloud-admin-status" class="ep-status"></p>
				<button type="button" id="etherpad-nextcloud-health-check">Test</button>
				<button type="button" id="etherpad-nextcloud-consistency-check">Check</button>
				<p id="etherpad-nextcloud-diagnostics-status" class="ep-status"></p>
			</form>
		</div>
	`
}

const saveStatus = () => document.getElementById('etherpad-nextcloud-admin-status')
const diagnosticsStatus = () => document.getElementById('etherpad-nextcloud-diagnostics-status')

/** A fetch whose response is resolved by the test, so overlapping requests
 * can be completed in a deliberately reversed order. */
const deferred = () => {
	let resolve
	const promise = new Promise((r) => {
		resolve = r
	})
	return {
		promise,
		respond: (body) => resolve({
			ok: true,
			status: 200,
			text: () => Promise.resolve(JSON.stringify(body)),
		}),
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
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
			ok: true,
			status: 200,
			text: () => Promise.resolve(JSON.stringify({ message: 'Connection ok.' })),
		})))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(diagnosticsStatus().textContent).toContain('Connection ok.')
		expect(saveStatus().textContent).toBe('')
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

		// The diagnostics request finishes first, the save afterwards; the save
		// response must not clear the diagnostics area.
		health.respond({ message: 'Connection ok.' })
		await flush()
		save.respond({ message: 'Saved.' })
		await flush()

		expect(diagnosticsStatus().textContent).toContain('Connection ok.')
		expect(saveStatus().textContent).toContain('Saved.')
	})

	it('clears the other area when a new action starts', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
			ok: true,
			status: 200,
			text: () => Promise.resolve(JSON.stringify({ message: 'Connection ok.' })),
		})))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()
		expect(diagnosticsStatus().textContent).toContain('Connection ok.')

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(diagnosticsStatus().textContent).toBe('')
		expect(diagnosticsStatus().classList.contains('ep-status-success')).toBe(false)
	})
})
