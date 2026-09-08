/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { isPadAccessMode } from './lib/constants.js'
import { ocRequestToken } from './lib/oc-compat.js'
import { fetchJsonWithTimeout as fetchJson } from './lib/fetch-helpers.js'

(function () {
	const root = document.getElementById('etherpad-nextcloud-embed-create')
	if (!(root instanceof HTMLElement)) {
		return
	}

	const parentFolderId = Number(root.getAttribute('data-parent-folder-id') || '')
	const createByParentUrl = String(root.getAttribute('data-create-by-parent-url') || '').trim()
	const templateRequestToken = String(root.getAttribute('data-request-token') || '').trim()
	const missingNameMessage = String(root.getAttribute('data-l10n-missing-name') || 'Pad name is required.')
	const invalidAccessModeMessage = String(root.getAttribute('data-l10n-invalid-access-mode') || 'Invalid access mode.')
	const incompleteConfigMessage = String(root.getAttribute('data-l10n-incomplete-config') || 'Embed configuration is incomplete.')
	const loadingNode = root.querySelector('[data-epnc-embed-create-loading]')
	const errorNode = root.querySelector('[data-epnc-embed-create-error]')
	const errorMessageNode = root.querySelector('[data-epnc-embed-create-error-message]')

	const requestToken = () => ocRequestToken(templateRequestToken)

	/**
	 * Post an `epnc:*` event to the host page that's embedding this iframe.
	 *
	 * Target-origin is `*` rather than a specific origin because the create
	 * page doesn't know the host's origin up-front (the host hasn't talked to
	 * us yet). The actual access control happens at iframe-load time via the
	 * route's CSP `frame-ancestors` header, which only lists the admin-
	 * configured `trusted_embed_origins`. Anyone receiving these messages is
	 * by construction already in that allowlist.
	 *
	 * No-ops if we're not actually embedded (window.parent === window).
	 */
	const postHostMessage = (type, payload) => {
		if (window.parent === window) {
			return
		}
		try {
			window.parent.postMessage(Object.assign({ type }, payload || {}), '*')
		} catch (e) {
			// Posting can throw on certain cross-origin / cross-process boundaries;
			// inline error rendering is the user-visible fallback, so the message
			// is purely advisory for the host.
		}
	}

	const showError = (message) => {
		if (loadingNode instanceof HTMLElement) {
			loadingNode.hidden = true
		}
		if (errorMessageNode instanceof HTMLElement) {
			errorMessageNode.textContent = String(message || 'Unknown error.')
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = false
		}
	}

	/**
	 * Emit a structured `epnc:create-failed` event AND render the inline error.
	 * The message is normalised once so the host's payload and the user-facing
	 * inline message never drift (an empty/undefined `message` would otherwise
	 * land in the iframe as "Unknown error." but in the postMessage payload as
	 * the empty string).
	 *
	 * `reason` is a coarse bucket so hosts can branch without parsing the
	 * HTTP status:
	 *   - 'invalid' — client-side validation failed (missing name, etc.)
	 *   - 'conflict' — backend returned 409 (e.g. duplicate filename)
	 *   - 'server'  — any other 4xx / 5xx
	 *   - 'network' — fetch itself failed (offline, CORS, timeout)
	 */
	const failCreate = (reason, message, status) => {
		const normalizedMessage = String(message || 'Unknown error.')
		showError(normalizedMessage)
		postHostMessage('epnc:create-failed', {
			reason,
			status: typeof status === 'number' ? status : null,
			message: normalizedMessage,
		})
	}

	const readLauncherParams = () => {
		const params = new URL(window.location.href).searchParams
		return {
			name: String(params.get('name') || '').trim(),
			accessMode: String(params.get('accessMode') || 'protected').trim(),
		}
	}

	const normalizeEmbedRedirectUrl = (value) => {
		const url = new URL(String(value || '').trim(), window.location.origin)
		if (url.origin !== window.location.origin) {
			throw new Error('Invalid embed URL origin.')
		}
		return url.pathname + url.search + url.hash
	}

	const classifyHttpStatus = (status) => {
		if (status === 409) {
			return 'conflict'
		}
		return 'server'
	}

	const run = async () => {
		if (!Number.isFinite(parentFolderId) || parentFolderId <= 0 || createByParentUrl === '') {
			failCreate('invalid', incompleteConfigMessage)
			return
		}
		if (requestToken() === '') {
			failCreate('invalid', 'CSRF request token is missing.')
			return
		}

		const { name, accessMode } = readLauncherParams()
		if (name === '') {
			failCreate('invalid', missingNameMessage)
			return
		}
		if (!isPadAccessMode(accessMode)) {
			failCreate('invalid', invalidAccessModeMessage)
			return
		}

		const body = new URLSearchParams()
		body.set('parentFolderId', String(parentFolderId))
		body.set('name', name)
		body.set('accessMode', accessMode)

		// Step 1: server-side create. Failures here are either network
		// (fetch threw — no HTTP status reached us) or server (we got a
		// status code back, including the 409 on duplicate filename).
		let data
		try {
			data = await fetchJson(createByParentUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: requestToken(),
				},
				body: body.toString(),
			})
		} catch (error) {
			const status = (error && typeof error.status === 'number') ? error.status : null
			const message = error instanceof Error ? error.message : 'Pad creation failed.'
			const reason = status === null ? 'network' : classifyHttpStatus(status)
			failCreate(reason, message, status)
			return
		}

		// Step 2: validate the server's response shape *and* the redirect
		// target before emitting success. A malformed or cross-origin
		// embed_url is a server-side bug, not a network failure — and we
		// must not announce success only to then announce failure to the
		// same host listener, which would leave them with contradictory
		// signals.
		if (!data || typeof data.embed_url !== 'string' || data.embed_url.trim() === '') {
			failCreate('server', 'Pad creation API did not return a valid embed URL.')
			return
		}
		let redirectTarget
		try {
			redirectTarget = normalizeEmbedRedirectUrl(data.embed_url)
		} catch (error) {
			const message = error instanceof Error ? error.message : 'Invalid embed URL.'
			failCreate('server', message)
			return
		}

		// Step 3: announce success once everything is definitively OK, then
		// navigate. Notify *before* the redirect: once we replace the iframe
		// location the host loses its handle on this script.
		postHostMessage('epnc:create-succeeded', {
			embed_url: data.embed_url,
			file_id: typeof data.file_id === 'number' ? data.file_id : null,
			pad_id: typeof data.pad_id === 'string' ? data.pad_id : '',
			access_mode: typeof data.access_mode === 'string' ? data.access_mode : '',
		})
		window.location.replace(redirectTarget)
	}

	void run()
})()
