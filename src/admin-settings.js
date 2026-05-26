(function () {
	'use strict'

	const root = document.getElementById('etherpad-nextcloud-admin-settings')
	const form = document.getElementById('etherpad-nextcloud-admin-form')
	const statusNode = document.getElementById('etherpad-nextcloud-admin-status')
	const healthButton = document.getElementById('etherpad-nextcloud-health-check')
	const consistencyButton = document.getElementById('etherpad-nextcloud-consistency-check')
	const retryPendingButton = document.getElementById('etherpad-nextcloud-retry-pending')
	const pendingActions = document.getElementById('etherpad-nextcloud-pending-actions')
	const pendingCountNode = document.getElementById('etherpad-nextcloud-pending-count')
	const allowExternalCheckbox = form ? form.querySelector('input[name="allow_external_pads"]') : null
	const allowlistRow = document.getElementById('external-pad-allowlist-row')
	const allowlistHint = document.getElementById('external-pad-allowlist-hint')
	const allowlistTextarea = document.getElementById('external-pad-allowlist')
	const fieldNodes = {
		etherpad_host: form.querySelector('[name="etherpad_host"]'),
		etherpad_api_host: form.querySelector('[name="etherpad_api_host"]'),
		etherpad_cookie_domain: form.querySelector('[name="etherpad_cookie_domain"]'),
		etherpad_api_key: form.querySelector('[name="etherpad_api_key"]'),
		sync_interval_seconds: form.querySelector('[name="sync_interval_seconds"]'),
		external_pad_allowlist: form.querySelector('[name="external_pad_allowlist"]'),
		trusted_embed_origins: form.querySelector('[name="trusted_embed_origins"]'),
	}

	if (!root || !form || !statusNode || !healthButton) {
		return
	}

	const saveUrl = root.getAttribute('data-save-url') || ''
	const healthUrl = root.getAttribute('data-health-url') || ''
	const consistencyUrl = root.getAttribute('data-consistency-url') || ''
	const retryPendingUrl = root.getAttribute('data-retry-pending-url') || ''
	const l10n = {
		saving: root.getAttribute('data-l10n-saving') || 'Saving settings...',
		saved: root.getAttribute('data-l10n-saved') || 'Settings saved.',
		checking: root.getAttribute('data-l10n-checking') || 'Running health check...',
		consistencyRunning: root.getAttribute('data-l10n-consistency-running') || 'Running consistency check...',
		healthOk: root.getAttribute('data-l10n-health-ok') || 'Health check successful.',
		consistencyOk: root.getAttribute('data-l10n-consistency-ok') || 'Consistency check successful.',
		requestFailed: root.getAttribute('data-l10n-request-failed') || 'Request failed.',
		savingFailed: root.getAttribute('data-l10n-saving-failed') || 'Failed to save settings.',
		healthFailed: root.getAttribute('data-l10n-health-failed') || 'Health check failed.',
		consistencyFailed: root.getAttribute('data-l10n-consistency-failed') || 'Consistency check failed.',
		pendingDeleteLabel: root.getAttribute('data-l10n-pending-delete-label') || 'Pending Etherpad deletes',
		retryFailed: root.getAttribute('data-l10n-retry-failed') || 'Pending delete retry failed.',
	}

	if (saveUrl === '' || healthUrl === '' || consistencyUrl === '' || retryPendingUrl === '') {
		return
	}

	function updateExternalSettingsVisibility() {
		const enabled = !!(allowExternalCheckbox && allowExternalCheckbox.checked)
		if (allowlistRow) {
			allowlistRow.style.display = enabled ? '' : 'none'
		}
		if (allowlistHint) {
			allowlistHint.style.display = enabled ? '' : 'none'
		}
		if (allowlistTextarea instanceof HTMLTextAreaElement) {
			allowlistTextarea.disabled = !enabled
			if (!enabled) {
				allowlistTextarea.classList.remove('ep-input-error')
				const errorNode = form.querySelector('[data-field-error="external_pad_allowlist"]')
				if (errorNode instanceof HTMLElement) {
					errorNode.textContent = ''
					errorNode.classList.remove('is-visible')
				}
			}
		}
	}

	function getPayload() {
		const data = new FormData(form)
		return {
			etherpad_host: String(data.get('etherpad_host') || '').trim(),
			etherpad_api_host: String(data.get('etherpad_api_host') || '').trim(),
			etherpad_cookie_domain: String(data.get('etherpad_cookie_domain') || '').trim(),
			etherpad_api_key: String(data.get('etherpad_api_key') || '').trim(),
			sync_interval_seconds: Number(data.get('sync_interval_seconds') || 120),
			delete_on_trash: data.has('delete_on_trash'),
			allow_external_pads: data.has('allow_external_pads'),
			external_pad_allowlist: String(data.get('external_pad_allowlist') || ''),
			trusted_embed_origins: String(data.get('trusted_embed_origins') || ''),
		}
	}

	function setStatus(message, state) {
		statusNode.textContent = message
		statusNode.classList.remove('ep-status-success', 'ep-status-error')
		if (state === 'success') {
			statusNode.classList.add('ep-status-success')
		} else if (state === 'error') {
			statusNode.classList.add('ep-status-error')
		}
	}

	function clearFieldErrors() {
		Object.keys(fieldNodes).forEach((field) => {
			const input = fieldNodes[field]
			if (input instanceof HTMLElement) {
				input.classList.remove('ep-input-error')
			}
			const errorNode = form.querySelector(`[data-field-error="${field}"]`)
			if (errorNode instanceof HTMLElement) {
				errorNode.textContent = ''
				errorNode.classList.remove('is-visible')
			}
		})
	}

	function showFieldError(field, message) {
		if (!field || !message) {
			return
		}
		const input = fieldNodes[field]
		if (input instanceof HTMLElement) {
			input.classList.add('ep-input-error')
		}
		const errorNode = form.querySelector(`[data-field-error="${field}"]`)
		if (errorNode instanceof HTMLElement) {
			errorNode.textContent = message
			errorNode.classList.add('is-visible')
		}
		if (input instanceof HTMLElement && typeof input.focus === 'function') {
			input.focus()
		}
	}

	function updatePendingDeleteUi(count) {
		const pendingCount = Number.isFinite(Number(count)) ? Number(count) : 0
		if (pendingActions instanceof HTMLElement) {
			pendingActions.style.display = pendingCount > 0 ? '' : 'none'
		}
		if (pendingCountNode instanceof HTMLElement) {
			pendingCountNode.textContent = `${l10n.pendingDeleteLabel}: ${String(pendingCount)}`
		}
		if (retryPendingButton instanceof HTMLButtonElement) {
			retryPendingButton.disabled = pendingCount <= 0
		}
	}

	async function postJson(url, payload) {
		const body = new URLSearchParams()
		Object.keys(payload).forEach((key) => {
			body.set(key, String(payload[key]))
		})

		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				requesttoken: String(OC.requestToken || ''),
			},
			body: body.toString(),
		})

		let data = null
		const responseText = await response.text()
		try {
			data = responseText !== '' ? JSON.parse(responseText) : null
		} catch (error) {
			data = null
		}

		if (!response.ok || !data || data.ok !== true) {
			const message = (data && data.message)
				? String(data.message)
				: (responseText !== '' ? responseText.slice(0, 200) : l10n.requestFailed)
			const err = new Error(message)
			err.field = (data && typeof data.field === 'string') ? data.field : ''
			throw err
		}

		return data
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		clearFieldErrors()
		setStatus(l10n.saving, null)
		try {
			const data = await postJson(saveUrl, getPayload())
			const versionSuffix = data && data.api_version ? ` api=${String(data.api_version)}` : ''
			setStatus(`${String(data.message || l10n.saved)}${versionSuffix}`, 'success')
		} catch (error) {
			if (error && typeof error.field === 'string' && error.field !== '') {
				showFieldError(error.field, error.message || l10n.savingFailed)
			}
			setStatus(error instanceof Error ? error.message : l10n.savingFailed, 'error')
		}
	})

	healthButton.addEventListener('click', async () => {
		clearFieldErrors()
		setStatus(l10n.checking, null)
		try {
			const data = await postJson(healthUrl, getPayload())
			const details = []
			if (typeof data.pad_count !== 'undefined') {
				details.push(`pad_count=${String(data.pad_count)}`)
			}
			if (typeof data.api_version === 'string' && data.api_version.trim() !== '') {
				details.push(`api=${data.api_version}`)
			}
			if (typeof data.latency_ms !== 'undefined') {
				details.push(`latency=${String(data.latency_ms)}ms`)
			}
			if (typeof data.target === 'string' && data.target.trim() !== '') {
				details.push(`target=${data.target}`)
			}
			if (typeof data.pending_delete_count !== 'undefined') {
				updatePendingDeleteUi(Number(data.pending_delete_count))
			}
			const suffix = details.length > 0 ? ` ${details.join(' | ')}` : ''
			const message = `${String(data.message || l10n.healthOk)}${suffix}`
			setStatus(message, 'success')
		} catch (error) {
			if (error && typeof error.field === 'string' && error.field !== '') {
				showFieldError(error.field, error.message || l10n.healthFailed)
			}
			setStatus(error instanceof Error ? error.message : l10n.healthFailed, 'error')
		}
	})

	if (consistencyButton instanceof HTMLElement) {
		consistencyButton.addEventListener('click', async () => {
			clearFieldErrors()
			setStatus(l10n.consistencyRunning, null)
			try {
				const data = await postJson(consistencyUrl, {})
				const bindingWithoutFile = Number(data.binding_without_file_count || 0)
				const message = `${String(data.message || l10n.consistencyOk)} binding_without_file=${String(bindingWithoutFile)}`
				setStatus(message, bindingWithoutFile > 0 ? 'error' : 'success')
			} catch (error) {
				setStatus(error instanceof Error ? error.message : l10n.consistencyFailed, 'error')
			}
		})
	}

	if (retryPendingButton instanceof HTMLElement) {
		retryPendingButton.addEventListener('click', async () => {
			clearFieldErrors()
			setStatus(l10n.checking, null)
			try {
				const data = await postJson(retryPendingUrl, {})
				const details = []
				if (typeof data.attempted !== 'undefined') {
					details.push(`attempted=${String(data.attempted)}`)
				}
				if (typeof data.resolved !== 'undefined') {
					details.push(`resolved=${String(data.resolved)}`)
				}
				if (typeof data.failed !== 'undefined') {
					details.push(`failed=${String(data.failed)}`)
				}
				if (typeof data.remaining !== 'undefined') {
					details.push(`remaining=${String(data.remaining)}`)
				}
				if (typeof data.remaining !== 'undefined') {
					updatePendingDeleteUi(Number(data.remaining || 0))
				}
				const suffix = details.length > 0 ? ` ${details.join(' | ')}` : ''
				setStatus(`${String(data.message || 'OK')}${suffix}`, 'success')
			} catch (error) {
				setStatus(error instanceof Error ? error.message : l10n.retryFailed, 'error')
			}
		})
	}

	if (allowExternalCheckbox) {
		allowExternalCheckbox.addEventListener('change', updateExternalSettingsVisibility)
	}
	updateExternalSettingsVisibility()
})()
