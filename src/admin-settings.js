(function () {
	'use strict'

	const root = document.getElementById('etherpad-nextcloud-admin-settings')
	const form = document.getElementById('etherpad-nextcloud-admin-form')
	const statusNode = document.getElementById('etherpad-nextcloud-admin-status')
	const diagnosticsStatusNode = document.getElementById('etherpad-nextcloud-diagnostics-status')
	const connectionStatusNode = document.getElementById('etherpad-nextcloud-connection-status')
	// Only the save area is required at startup; older markup without these
	// areas still has to show its feedback somewhere.
	const diagnosticsTarget = diagnosticsStatusNode instanceof HTMLElement
		? diagnosticsStatusNode
		: statusNode
	const connectionTarget = connectionStatusNode instanceof HTMLElement
		? connectionStatusNode
		: diagnosticsTarget
	const healthButton = document.getElementById('etherpad-nextcloud-health-check')
	const consistencyButton = document.getElementById('etherpad-nextcloud-consistency-check')
	const retryPendingButton = document.getElementById('etherpad-nextcloud-retry-pending')
	const pendingActions = document.getElementById('etherpad-nextcloud-pending-actions')
	const pendingCountNode = document.getElementById('etherpad-nextcloud-pending-count')
	const allowExternalCheckbox = form ? form.querySelector('input[name="allow_external_pads"]') : null
	const protectedPadsCheckbox = form ? form.querySelector('input[name="enable_protected_pads"]') : null
	const publicPadsCheckbox = form ? form.querySelector('input[name="enable_public_pads"]') : null
	const padTypesNoneHint = document.getElementById('pad-types-none-hint')
	const cookieWarningNode = document.getElementById('epnc-cookie-warning')
	const connectionChecksNode = document.getElementById('etherpad-nextcloud-connection-checks')
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
		checking: root.getAttribute('data-l10n-checking') || 'Testing Etherpad connection...',
		consistencyRunning: root.getAttribute('data-l10n-consistency-running') || 'Running consistency check...',
		healthOk: root.getAttribute('data-l10n-health-ok') || 'Etherpad connection test successful.',
		consistencyOk: root.getAttribute('data-l10n-consistency-ok') || 'Consistency check successful.',
		requestFailed: root.getAttribute('data-l10n-request-failed') || 'Request failed.',
		savingFailed: root.getAttribute('data-l10n-saving-failed') || 'Failed to save settings.',
		healthFailed: root.getAttribute('data-l10n-health-failed') || 'Etherpad connection test failed.',
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

	function updatePadTypesHint() {
		const noneEnabled = !!(protectedPadsCheckbox && publicPadsCheckbox)
			&& !protectedPadsCheckbox.checked
			&& !publicPadsCheckbox.checked
		if (padTypesNoneHint instanceof HTMLElement) {
			// Write the text instead of toggling visibility: a live region only
			// announces content changes, and an element returning from
			// `display: none` reads as initial content. `.ep-field-hint:empty`
			// collapses the empty paragraph, so it looks the same.
			const message = noneEnabled ? (padTypesNoneHint.dataset.message || '') : ''
			// Rewriting the same text would announce it again on load.
			if (padTypesNoneHint.textContent !== message) {
				padTypesNoneHint.textContent = message
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
			enable_protected_pads: data.has('enable_protected_pads'),
			enable_public_pads: data.has('enable_public_pads'),
			allow_external_pads: data.has('allow_external_pads'),
			external_pad_allowlist: String(data.get('external_pad_allowlist') || ''),
			trusted_embed_origins: String(data.get('trusted_embed_origins') || ''),
		}
	}

	// Writing a status touches only its own area, so a late response cannot
	// wipe out the other action's result.
	function setStatus(message, state, node = statusNode) {
		if (!(node instanceof HTMLElement)) {
			return
		}
		node.textContent = message
		node.classList.remove('ep-status-success', 'ep-status-warning', 'ep-status-error')
		if (state === 'success' || state === 'warning' || state === 'error') {
			node.classList.add(`ep-status-${state}`)
		}
	}

	// Starting one does clear the other: its result predates this action and
	// would be read as belonging to it.
	function beginStatus(message, node = statusNode) {
		for (const other of [statusNode, diagnosticsTarget, connectionTarget]) {
			if (other instanceof HTMLElement && other !== node) {
				other.textContent = ''
				other.classList.remove('ep-status-success', 'ep-status-warning', 'ep-status-error')
			}
		}
		setStatus(message, null, node)
	}

	// Each result is shown at the field it came from: a green tick where
	// things are fine, the message itself where they are not. Anything that
	// belongs to no single field falls back to the list under the button.
	function renderConnectionChecks(checks) {
		for (const slot of document.querySelectorAll('[data-check-result]')) {
			slot.replaceChildren()
			slot.className = 'ep-check-result'
		}
		if (connectionChecksNode instanceof HTMLElement) {
			connectionChecksNode.replaceChildren()
		}
		if (!Array.isArray(checks)) {
			return
		}
		for (const check of checks) {
			if (!check || typeof check.label !== 'string') {
				continue
			}
			const status = String(check.status || 'skipped')
			const detail = typeof check.detail === 'string' ? check.detail : ''
			const slot = check.field
				? document.querySelector(`[data-check-result="${CSS.escape(String(check.field))}"]`)
				: null
			if (slot instanceof HTMLElement) {
				slot.className = `ep-check-result ep-check-${status}`
				// A passing field needs no prose — the tick and the label say
				// it. A failing one needs the reason right there.
				slot.textContent = status === 'ok' ? check.label : (detail || check.label)
				continue
			}
			appendCheckRow(status, check.label, detail)
		}
	}

	function appendCheckRow(status, label, detail) {
		if (!(connectionChecksNode instanceof HTMLElement)) {
			return
		}
		const row = document.createElement('li')
		row.className = `ep-check ep-check-${status}`
		const labelNode = document.createElement('span')
		labelNode.className = 'ep-check-label'
		labelNode.textContent = label
		row.appendChild(labelNode)
		if (detail !== '') {
			const detailNode = document.createElement('span')
			detailNode.className = 'ep-check-detail'
			detailNode.textContent = detail
			row.appendChild(detailNode)
		}
		connectionChecksNode.appendChild(row)
	}

	function updateCookieWarning(protectedPads) {
		if (!(cookieWarningNode instanceof HTMLElement)) {
			return
		}
		const message = (protectedPads && protectedPads.ok === false && typeof protectedPads.message === 'string')
			? protectedPads.message
			: ''
		if (cookieWarningNode.textContent !== message) {
			cookieWarningNode.textContent = message
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
		beginStatus(l10n.saving)
		try {
			const data = await postJson(saveUrl, getPayload())
			// Saving invalidates the list — only a connection test produces one —
			// so the standalone warning takes over again.
			renderConnectionChecks([])
			updateCookieWarning(data.protected_pads)
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
		beginStatus(l10n.checking, connectionTarget)
		try {
			const data = await postJson(healthUrl, getPayload())
			if (typeof data.pending_delete_count !== 'undefined') {
				updatePendingDeleteUi(Number(data.pending_delete_count))
			}
			// The per-field results carry the target, pad count and latency, and
			// the protected-pads verdict, so the summary stays a summary.
			renderConnectionChecks(data.checks)
			updateCookieWarning(null)
			const needsAttention = Array.isArray(data.checks)
				&& data.checks.some((check) => check && check.status === 'warning')
			setStatus(String(data.message || l10n.healthOk), needsAttention ? 'warning' : 'success', connectionTarget)
		} catch (error) {
			if (error && typeof error.field === 'string' && error.field !== '') {
				showFieldError(error.field, error.message || l10n.healthFailed)
			}
			renderConnectionChecks([])
			setStatus(error instanceof Error ? error.message : l10n.healthFailed, 'error', connectionTarget)
		}
	})

	if (consistencyButton instanceof HTMLElement) {
		consistencyButton.addEventListener('click', async () => {
			clearFieldErrors()
			beginStatus(l10n.consistencyRunning, diagnosticsTarget)
			try {
				const data = await postJson(consistencyUrl, {})
				const bindingWithoutFile = Number(data.binding_without_file_count || 0)
				const message = `${String(data.message || l10n.consistencyOk)} binding_without_file=${String(bindingWithoutFile)}`
				setStatus(message, bindingWithoutFile > 0 ? 'error' : 'success', diagnosticsTarget)
			} catch (error) {
				setStatus(error instanceof Error ? error.message : l10n.consistencyFailed, 'error', diagnosticsTarget)
			}
		})
	}

	if (retryPendingButton instanceof HTMLElement) {
		retryPendingButton.addEventListener('click', async () => {
			clearFieldErrors()
			beginStatus(l10n.checking, diagnosticsTarget)
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
				setStatus(`${String(data.message || 'OK')}${suffix}`, 'success', diagnosticsTarget)
			} catch (error) {
				setStatus(error instanceof Error ? error.message : l10n.retryFailed, 'error', diagnosticsTarget)
			}
		})
	}

	if (allowExternalCheckbox) {
		allowExternalCheckbox.addEventListener('change', updateExternalSettingsVisibility)
	}
	updateExternalSettingsVisibility()

	for (const checkbox of [protectedPadsCheckbox, publicPadsCheckbox]) {
		if (checkbox) {
			checkbox.addEventListener('change', updatePadTypesHint)
		}
	}
	updatePadTypesHint()
})()
