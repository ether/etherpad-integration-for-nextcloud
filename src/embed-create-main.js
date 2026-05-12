/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
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

	const run = async () => {
		if (!Number.isFinite(parentFolderId) || parentFolderId <= 0 || createByParentUrl === '') {
			showError(incompleteConfigMessage)
			return
		}
		if (requestToken() === '') {
			showError('CSRF request token is missing.')
			return
		}

		const { name, accessMode } = readLauncherParams()
		if (name === '') {
			showError(missingNameMessage)
			return
		}
		if (accessMode !== 'protected' && accessMode !== 'public') {
			showError(invalidAccessModeMessage)
			return
		}

		const body = new URLSearchParams()
		body.set('parentFolderId', String(parentFolderId))
		body.set('name', name)
		body.set('accessMode', accessMode)

		try {
			const data = await fetchJson(createByParentUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: requestToken(),
				},
				body: body.toString(),
			})
			if (!data || typeof data.embed_url !== 'string' || data.embed_url.trim() === '') {
				throw new Error('Pad creation API did not return a valid embed URL.')
			}
			window.location.replace(normalizeEmbedRedirectUrl(data.embed_url))
		} catch (error) {
			showError(error instanceof Error ? error.message : 'Pad creation failed.')
		}
	}

	void run()
})()
