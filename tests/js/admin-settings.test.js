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
			data-templates-url="/templates"
			data-templates-delete-url="/templates/delete"
			data-l10n-template-delete="Delete"
			data-l10n-template-delete-label="Vorlage {name} löschen"
			data-l10n-template-too-large="Template file is too large."
			data-l10n-template-confirm-delete="Delete this template for everyone?"
			data-l10n-template-confirm-replace="Replace the existing template of that name?">
			<form id="etherpad-nextcloud-admin-form">
				<input name="etherpad_host" value="https://pad.example.org">
				<span class="ep-check-result" data-check-result="etherpad_host"></span>
				<span class="ep-check-result" data-check-result="etherpad_cookie_domain"></span>
				<input name="etherpad_api_host" value="">
				<input name="etherpad_cookie_domain" value="">
				<input name="etherpad_api_key" value="">
				<span class="ep-field-error" data-field-error="etherpad_api_key"></span>
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
				<ul id="epnc-template-list"></ul>
				<p id="epnc-template-empty"></p>
				<input type="file" id="epnc-template-file">
				<button type="button" id="epnc-template-upload">Upload</button>
				<p id="epnc-template-status" class="ep-status"></p>
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
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'All checks passed.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		expect(connectionStatus().textContent).toContain('All checks passed.')
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
		health.respond({ message: 'All checks passed.' })
		await flush()
		save.respond({ message: 'Saved.' })
		await flush()

		expect(connectionStatus().textContent).toContain('All checks passed.')
		expect(connectionStatus().classList.contains('ep-status-success')).toBe(true)
		expect(saveStatus().textContent).toContain('Saved.')
		expect(saveStatus().classList.contains('ep-status-success')).toBe(true)
	})

	it('clears the other area when a new action starts', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({ message: 'All checks passed.' }))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()
		expect(connectionStatus().textContent).toContain('All checks passed.')

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(connectionStatus().textContent).toBe('')
		expect(connectionStatus().classList.contains('ep-status-success')).toBe(false)
	})


})

describe('protected pads cookie warning', () => {
	const cookieSlot = () => document.querySelector('[data-check-result="etherpad_cookie_domain"]')

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

	it('shows each result at the field it came from', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Check finished. Some settings need attention.',
			checks: [
				{ id: 'base_url', status: 'ok', label: 'Etherpad base URL reachable', detail: 'https://pad.example.org', field: 'etherpad_host' },
				{ id: 'protected_pads', status: 'warning', label: 'Protected pads: session cookie', detail: 'Hosts do not share a parent domain.', field: 'etherpad_cookie_domain' },
			],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		const base = document.querySelector('[data-check-result="etherpad_host"]')
		const cookie = document.querySelector('[data-check-result="etherpad_cookie_domain"]')
		// A passing field only needs the label; a failing one needs the reason.
		expect(base.classList.contains('ep-check-ok')).toBe(true)
		expect(base.textContent).toBe('Etherpad base URL reachable')
		expect(cookie.classList.contains('ep-check-warning')).toBe(true)
		expect(cookie.textContent).toBe('Hosts do not share a parent domain.')
	})

	it('lets a warning win over a pass on the same field', async () => {
		// With no separate API URL both lines describe the base URL input.
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Check finished. Some settings need attention.',
			checks: [
				{ id: 'api', status: 'ok', label: 'Etherpad API reachable', detail: '', field: 'etherpad_host' },
				{ id: 'base_url', status: 'warning', label: 'Etherpad base URL reachable', detail: 'Points into this server\'s own network.', field: 'etherpad_host' },
			],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		const slot = document.querySelector('[data-check-result="etherpad_host"]')
		expect(slot.classList.contains('ep-check-warning')).toBe(true)
		expect(slot.textContent).toContain('own network')
	})

	it('keeps the warning even when the passing line comes last', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Check finished. Some settings need attention.',
			checks: [
				{ id: 'base_url', status: 'warning', label: 'Etherpad base URL reachable', detail: 'Points into this server\'s own network.', field: 'etherpad_host' },
				{ id: 'api', status: 'ok', label: 'Etherpad API reachable', detail: '', field: 'etherpad_host' },
			],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		const slot = document.querySelector('[data-check-result="etherpad_host"]')
		expect(slot.classList.contains('ep-check-warning')).toBe(true)
	})

	it('clears field results while the new run is still in flight', async () => {
		const cookie = document.querySelector('[data-check-result="etherpad_cookie_domain"]')
		cookie.className = 'ep-check-result ep-check-warning'
		cookie.textContent = 'Stale problem.'
		const health = deferred()
		vi.stubGlobal('fetch', vi.fn(() => health.promise))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		// Still waiting: a green tick or an old problem next to "Testing…"
		// would describe the previous values.
		expect(cookie.textContent).toBe('')
		expect(cookie.classList.contains('ep-check-warning')).toBe(false)

		health.respond({
			message: 'All checks passed.',
			checks: [{ id: 'protected_pads', status: 'ok', label: 'Protected pads: session cookie', detail: '.example.org', field: 'etherpad_cookie_domain' }],
		})
		await flush()

		expect(cookie.classList.contains('ep-check-ok')).toBe(true)
	})

	it('marks the field a failed connection test points at', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
			ok: false,
			status: 502,
			text: () => Promise.resolve(JSON.stringify({
				ok: false,
				message: 'Etherpad connection test failed: no or wrong API Key',
				field: 'etherpad_api_key',
			})),
		})))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		const error = document.querySelector('[data-field-error="etherpad_api_key"]')
		expect(error.classList.contains('is-visible')).toBe(true)
		expect(error.textContent).toContain('wrong API Key')
	})

	it('drops field results when a save fails', async () => {
		const cookie = document.querySelector('[data-check-result="etherpad_cookie_domain"]')
		cookie.className = 'ep-check-result ep-check-ok'
		cookie.textContent = 'Protected pads: session cookie'
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
			ok: false,
			status: 400,
			text: () => Promise.resolve(JSON.stringify({ ok: false, message: 'Failed.' })),
		})))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		// The form values changed, so the old verdict no longer describes them.
		expect(cookie.textContent).toBe('')
	})

	it('drops stale per-field results when settings are saved', async () => {
		// The save answers with the cookie verdict only, so results from the
		// earlier connection test must not linger at the other fields.
		vi.stubGlobal('fetch', vi.fn((url) => Promise.resolve(okResponse(url === '/health'
			? { message: 'All checks passed.', checks: [{ id: 'base_url', status: 'ok', label: 'Etherpad base URL reachable', detail: '', field: 'etherpad_host' }] }
			: { message: 'Saved.', checks: [{ id: 'protected_pads', status: 'ok', label: 'Protected pads: session cookie', detail: '.example.org', field: 'etherpad_cookie_domain' }] }))))
		await import(MODULE)
		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()
		expect(document.querySelector('[data-check-result="etherpad_host"]').textContent).not.toBe('')

		// Saving does not re-run the checks, so keeping them would show a
		// verdict about values that no longer apply.
		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(document.querySelector('[data-check-result="etherpad_host"]').textContent).toBe('')
	})

	it('marks the summary as a warning when any check needs attention', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Check finished. Some settings need attention.',
			checks: [
				{ id: 'api', status: 'ok', label: 'Etherpad API reachable', detail: '', field: 'etherpad_api_host' },
				{ id: 'protected_pads', status: 'warning', label: 'Protected pads: session cookie', detail: 'Hosts do not share a parent domain.', field: 'etherpad_cookie_domain' },
			],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		// The API answered, so not an error — but not an unqualified pass either.
		const status = document.getElementById('etherpad-nextcloud-connection-status')
		expect(status.textContent).toContain('Some settings need attention')
		expect(status.classList.contains('ep-status-warning')).toBe(true)
		expect(status.classList.contains('ep-status-success')).toBe(false)
	})

	it('keeps the summary green when every check passes', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'All checks passed.',
			checks: [{ id: 'api', status: 'ok', label: 'Etherpad API reachable', detail: '', field: 'etherpad_api_host' }],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-health-check').click()
		await flush()

		const status = document.getElementById('etherpad-nextcloud-connection-status')
		expect(status.classList.contains('ep-status-success')).toBe(true)
		expect(status.classList.contains('ep-status-warning')).toBe(false)
	})

	it('refreshes the verdict when settings are saved', async () => {
		cookieSlot().textContent = 'Hosts do not share a parent domain.'
		cookieSlot().className = 'ep-check-result ep-check-warning'
		// The save fixed the domain, so the stale warning has to go without a
		// reload or a separate connection test.
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Saved.',
			checks: [{ id: 'protected_pads', status: 'ok', label: 'Protected pads: session cookie', detail: '.example.org', field: 'etherpad_cookie_domain' }],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(cookieSlot().classList.contains('ep-check-warning')).toBe(false)
		expect(cookieSlot().textContent).toBe('Protected pads: session cookie')
	})

	it('raises a new warning on save when the domain was broken', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Saved.',
			checks: [{ id: 'protected_pads', status: 'warning', label: 'Protected pads: session cookie', detail: 'Hosts do not share a parent domain.', field: 'etherpad_cookie_domain' }],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(cookieSlot().textContent).toBe('Hosts do not share a parent domain.')
	})

	it('marks the verdict as skipped when protected pads are switched off', async () => {
		cookieSlot().textContent = 'Hosts do not share a parent domain.'
		cookieSlot().className = 'ep-check-result ep-check-warning'
		// Skipped, not a problem: there is nothing to fix on an instance that
		// only offers public pads.
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse({
			message: 'Saved.',
			checks: [{ id: 'protected_pads', status: 'skipped', label: 'Protected pads: session cookie', detail: 'Protected pads are switched off.', field: 'etherpad_cookie_domain' }],
		}))))
		await import(MODULE)

		document.getElementById('etherpad-nextcloud-admin-form').requestSubmit()
		await flush()

		expect(cookieSlot().classList.contains('ep-check-skipped')).toBe(true)
		expect(cookieSlot().classList.contains('ep-check-warning')).toBe(false)
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

describe('shared templates', () => {
	const list = () => document.getElementById('epnc-template-list')
	const status = () => document.getElementById('epnc-template-status')
	const rows = () => [...list().querySelectorAll('.ep-template-row')]

	const templateResponse = (names) => okResponse({ templates: names.map((name) => ({ name, size: 1, modified: 1 })) })

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

	it('lists what the server reports', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(templateResponse(['Meeting notes.pad', 'Retro.pad']))))
		await import(MODULE)
		await flush()

		expect(rows().map((row) => row.querySelector('.ep-template-name').textContent))
			.toEqual(['Meeting notes.pad', 'Retro.pad'])
		expect(document.getElementById('epnc-template-empty').style.display).toBe('none')
	})

	/**
	 * A failing listing used to look exactly like an empty one, so broken
	 * appdata permissions read as "no templates yet".
	 */
	it('reports a failing listing instead of showing an empty one', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
			ok: false,
			status: 500,
			text: () => Promise.resolve(JSON.stringify({ ok: false, message: 'Could not read the templates.' })),
		})))
		await import(MODULE)
		await flush()

		expect(status().textContent).toContain('Could not read the templates.')
		expect(status().classList.contains('ep-status-error')).toBe(true)
		// The claim "none uploaded" is exactly what we do not know here.
		expect(document.getElementById('epnc-template-empty').style.display).toBe('none')
	})

	/** Each row's only visible label is "Delete", so the name has to be read out. */
	it('names the template each delete button removes', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(templateResponse(['Meeting notes.pad']))))
		await import(MODULE)
		await flush()

		const remove = list().querySelector('button')
		// Read from the page, not glued together here: the sentence differs per
		// language, and only the placeholder is ours to fill.
		expect(remove.getAttribute('aria-label')).toBe('Vorlage Meeting notes.pad löschen')
	})

	/**
	 * Uploading a template says nothing about the connection test or the last
	 * save, so it must not wipe their results.
	 */
	it('keeps the template status apart from the other results', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(templateResponse([]))))
		await import(MODULE)
		await flush()

		const saved = document.getElementById('etherpad-nextcloud-admin-status')
		saved.textContent = 'Settings saved.'
		saved.classList.add('ep-status-success')

		await uploadFile('agenda.pad', '---\n---\n')

		expect(saved.textContent).toBe('Settings saved.')
		expect(saved.classList.contains('ep-status-success')).toBe(true)
	})

	/**
	 * The server refuses it anyway, but only after the whole file has been read
	 * into memory and posted — where it can hit post_max_size instead, and the
	 * admin gets a failure that says nothing.
	 */
	it('refuses an oversized file before reading it', async () => {
		const posts = []
		vi.stubGlobal('fetch', vi.fn((url, options) => {
			if (options && options.method === 'POST') {
				posts.push(url)
			}
			return Promise.resolve(templateResponse([]))
		}))
		await import(MODULE)
		await flush()

		let read = false
		const input = document.getElementById('epnc-template-file')
		Object.defineProperty(input, 'files', {
			configurable: true,
			value: [{
				name: 'huge.pad',
				size: 3 * 1024 * 1024,
				text: () => { read = true; return Promise.resolve('x') },
			}],
		})
		input.dispatchEvent(new Event('change'))
		await flush()

		expect(read).toBe(false)
		expect(posts).toHaveLength(0)
		expect(status().textContent).toContain('too large')
	})

	/** A file name is data, never markup. */
	it('renders a template name as text', async () => {
		vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(templateResponse(['<img src=x onerror=alert(1)>.pad']))))
		await import(MODULE)
		await flush()

		const name = list().querySelector('.ep-template-name')
		expect(name.textContent).toBe('<img src=x onerror=alert(1)>.pad')
		expect(name.querySelector('img')).toBeNull()
	})

	it('uploads as JSON and reloads the list', async () => {
		const calls = []
		vi.stubGlobal('fetch', vi.fn((url, options) => {
			calls.push({ url, options })
			return Promise.resolve(url === '/templates' && options && options.method === 'POST'
				? okResponse({ message: 'Template saved.' })
				: templateResponse(['Meeting notes.pad']))
		}))
		await import(MODULE)
		await flush()

		await uploadFile('Meeting notes.pad', 'content')

		const upload = calls.find((c) => c.options && c.options.method === 'POST')
		expect(upload.options.headers['Content-Type']).toBe('application/json')
		expect(JSON.parse(upload.options.body)).toMatchObject({ name: 'Meeting notes.pad', replace: false })
		expect(status().textContent).toContain('Template saved.')
	})

	/**
	 * The server refuses to overwrite unless asked, so a taken name comes back
	 * as a question. Confirming repeats the upload with replace set.
	 */
	it('asks before replacing a template of the same name', async () => {
		const bodies = []
		vi.stubGlobal('fetch', vi.fn((url, options) => {
			if (options && options.method === 'POST') {
				bodies.push(JSON.parse(options.body))
				return Promise.resolve(bodies.length === 1
					? { ok: false, status: 400, text: () => Promise.resolve(JSON.stringify({ ok: false, message: 'exists', field: 'template_exists' })) }
					: okResponse({ message: 'Template saved.' }))
			}
			return Promise.resolve(templateResponse([]))
		}))
		vi.stubGlobal('confirm', vi.fn(() => true))
		await import(MODULE)
		await flush()

		await uploadFile('Meeting notes.pad', 'content')

		expect(bodies.map((b) => b.replace)).toEqual([false, true])
		expect(status().textContent).toContain('Template saved.')
	})

	it('leaves the template alone when the replace prompt is declined', async () => {
		const bodies = []
		vi.stubGlobal('fetch', vi.fn((url, options) => {
			if (options && options.method === 'POST') {
				bodies.push(JSON.parse(options.body))
				return Promise.resolve({ ok: false, status: 400, text: () => Promise.resolve(JSON.stringify({ ok: false, message: 'exists', field: 'template_exists' })) })
			}
			return Promise.resolve(templateResponse([]))
		}))
		vi.stubGlobal('confirm', vi.fn(() => false))
		await import(MODULE)
		await flush()

		await uploadFile('Meeting notes.pad', 'content')

		expect(bodies).toHaveLength(1)
		expect(status().textContent).toBe('')
	})

	it('deletes after confirmation and skips it otherwise', async () => {
		const posts = []
		vi.stubGlobal('fetch', vi.fn((url, options) => {
			if (options && options.method === 'POST') {
				posts.push(url)
				return Promise.resolve(okResponse({ message: 'Template deleted.', name: 'Retro.pad' }))
			}
			return Promise.resolve(templateResponse(['Retro.pad']))
		}))
		const confirmMock = vi.fn(() => false)
		vi.stubGlobal('confirm', confirmMock)
		await import(MODULE)
		await flush()

		rows()[0].querySelector('button').click()
		await flush()
		expect(posts).toHaveLength(0)

		confirmMock.mockReturnValue(true)
		rows()[0].querySelector('button').click()
		await flush()
		expect(posts).toEqual(['/templates/delete'])
	})

	const uploadFile = async (name, content, size = content.length) => {
		const input = document.getElementById('epnc-template-file')
		Object.defineProperty(input, 'files', {
			configurable: true,
			value: [{ name, size, text: () => Promise.resolve(content) }],
		})
		input.dispatchEvent(new Event('change'))
		await flush()
	}
})
