(function () {
	'use strict'

	const root = document.getElementById('etherpad-nextcloud-admin-settings')
	const form = document.getElementById('etherpad-nextcloud-admin-form')
	const statusNode = document.getElementById('etherpad-nextcloud-admin-status')
	const diagnosticsTarget = document.getElementById('etherpad-nextcloud-diagnostics-status')
	const connectionTarget = document.getElementById('etherpad-nextcloud-connection-status')
	const healthButton = document.getElementById('etherpad-nextcloud-health-check')
	const consistencyButton = document.getElementById('etherpad-nextcloud-consistency-check')
	const retryPendingButton = document.getElementById('etherpad-nextcloud-retry-pending')
	const pendingActions = document.getElementById('etherpad-nextcloud-pending-actions')
	const pendingCountNode = document.getElementById('etherpad-nextcloud-pending-count')
	const allowExternalCheckbox = form ? form.querySelector('input[name="allow_external_pads"]') : null
	const protectedPadsCheckbox = form ? form.querySelector('input[name="enable_protected_pads"]') : null
	const publicPadsCheckbox = form ? form.querySelector('input[name="enable_public_pads"]') : null
	const padTypesNoneHint = document.getElementById('pad-types-none-hint')
	const allowlistRow = document.getElementById('external-pad-allowlist-row')
	const allowlistHint = document.getElementById('external-pad-allowlist-hint')
	const allowlistTextarea = document.getElementById('external-pad-allowlist')
	const templateListNode = document.getElementById('epnc-template-list')
	const templateEmptyNode = document.getElementById('epnc-template-empty')
	const templateFileInput = document.getElementById('epnc-template-file')
	const templateUploadButton = document.getElementById('epnc-template-upload')
	const templateStatusNode = document.getElementById('epnc-template-status')
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
		consistencyOk: root.getAttribute('data-l10n-consistency-ok') || 'Consistency check successful.',
		requestFailed: root.getAttribute('data-l10n-request-failed') || 'Request failed.',
		savingFailed: root.getAttribute('data-l10n-saving-failed') || 'Failed to save settings.',
		healthFailed: root.getAttribute('data-l10n-health-failed') || 'Etherpad connection test failed.',
		consistencyFailed: root.getAttribute('data-l10n-consistency-failed') || 'Consistency check failed.',
		pendingDeleteLabel: root.getAttribute('data-l10n-pending-delete-label') || 'Pending Etherpad deletes',
		retryFailed: root.getAttribute('data-l10n-retry-failed') || 'Pending delete retry failed.',
		templateUploading: root.getAttribute('data-l10n-template-uploading') || 'Uploading template...',
		templateDelete: root.getAttribute('data-l10n-template-delete') || 'Delete',
		templateTooLarge: root.getAttribute('data-l10n-template-too-large') || 'Template file is too large.',
		templateDeleteLabel: root.getAttribute('data-l10n-template-delete-label') || 'Delete template {name}',
		templateConfirmDelete: root.getAttribute('data-l10n-template-confirm-delete') || 'Delete this template for everyone?',
		templateFailed: root.getAttribute('data-l10n-template-failed') || 'Template request failed.',
		templateConfirmReplace: root.getAttribute('data-l10n-template-confirm-replace') || 'Replace the existing template of that name?',
	}
	const templatesUrl = root.getAttribute('data-templates-url') || ''
	const templatesDeleteUrl = root.getAttribute('data-templates-delete-url') || ''

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
	// would be read as belonging to it. The template status is its own group —
	// uploading a template makes neither a connection test nor a save result
	// stale, and vice versa.
	function beginStatus(message, node = statusNode) {
		const group = node === templateStatusNode
			? [templateStatusNode]
			: [statusNode, diagnosticsTarget, connectionTarget]
		for (const other of group) {
			if (other instanceof HTMLElement && other !== node) {
				other.textContent = ''
				other.classList.remove('ep-status-success', 'ep-status-warning', 'ep-status-error')
			}
		}
		setStatus(message, null, node)
	}

	// Higher wins when two checks land on the same field, so a problem is
	// never hidden by a passing verdict that happened to come later.
	const CHECK_SEVERITY = { skipped: 0, ok: 1, warning: 2 }

	// Each result is shown at the field it came from: a green tick where
	// things are fine, the message itself where they are not.
	function renderConnectionChecks(checks) {
		for (const slot of document.querySelectorAll('[data-check-result]')) {
			slot.textContent = ''
			slot.className = 'ep-check-result'
			delete slot.dataset.checkStatus
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
			if (!(slot instanceof HTMLElement)) {
				continue
			}
			const shown = slot.dataset.checkStatus
			if (shown && (CHECK_SEVERITY[shown] ?? 0) > (CHECK_SEVERITY[status] ?? 0)) {
				continue
			}
			slot.dataset.checkStatus = status
			slot.className = `ep-check-result ep-check-${status}`
			// A passing field needs no prose — the tick and the label say it.
			// A failing one needs the reason right there.
			slot.textContent = status === 'ok' ? check.label : (detail || check.label)
		}
	}

	// Shared templates: an admin uploads .pad files that everyone then sees as
	// tiles in Nextcloud's picker.
	function renderTemplates(templates) {
		if (!(templateListNode instanceof HTMLElement)) {
			return
		}
		templateListNode.replaceChildren()
		const list = Array.isArray(templates) ? templates : []
		showTemplateEmptyState(list.length === 0)
		for (const template of list) {
			if (!template || typeof template.name !== 'string') {
				continue
			}
			templateListNode.appendChild(buildTemplateRow(template.name))
		}
	}

	function buildTemplateRow(name) {
		const row = document.createElement('li')
		row.className = 'ep-template-row'

		const label = document.createElement('span')
		label.className = 'ep-template-name'
		label.textContent = name
		row.appendChild(label)

		const remove = document.createElement('button')
		remove.type = 'button'
		remove.textContent = l10n.templateDelete
		// The visible label repeats on every row, so the accessible name has to
		// carry the name of the template this one removes.
		remove.setAttribute('aria-label', l10n.templateDeleteLabel.replace('{name}', name))
		remove.addEventListener('click', () => {
			if (!window.confirm(l10n.templateConfirmDelete)) {
				return
			}
			void deleteTemplate(name)
		})
		row.appendChild(remove)
		return row
	}

	function showTemplateEmptyState(visible) {
		if (templateEmptyNode instanceof HTMLElement) {
			templateEmptyNode.style.display = visible ? '' : 'none'
		}
	}

	async function loadTemplates() {
		if (templatesUrl === '' || !(templateListNode instanceof HTMLElement)) {
			return
		}
		// "No shared templates yet" is an answer, and we do not have one until
		// the request comes back — showing it next to an error would claim the
		// list is empty when we simply could not read it.
		showTemplateEmptyState(false)
		try {
			const response = await fetch(templatesUrl, {
				credentials: 'same-origin',
				headers: { requesttoken: String(OC.requestToken || '') },
			})
			const data = await readJsonResponse(response)
			renderTemplates(data.templates)
		} catch (error) {
			showTemplateEmptyState(false)
			setStatus(error instanceof Error ? error.message : l10n.templateFailed, 'error', templateStatusNode)
		}
	}

	// The same limit the server enforces. Without it a mis-picked large file is
	// read into memory and posted, only to hit post_max_size or the memory
	// limit — a failure that says nothing, instead of the sentence below.
	const MAX_TEMPLATE_BYTES = 2 * 1024 * 1024

	async function uploadTemplate(file, replace = false) {
		if (typeof file.size === 'number' && file.size > MAX_TEMPLATE_BYTES) {
			setStatus(l10n.templateTooLarge, 'error', templateStatusNode)
			return
		}
		beginStatus(l10n.templateUploading, templateStatusNode)
		try {
			const content = await file.text()
			const data = await postJsonBody(templatesUrl, { name: file.name, content, replace })
			setStatus(String(data.message || ''), 'success', templateStatusNode)
			await loadTemplates()
		} catch (error) {
			// The server refuses to overwrite unless asked, so a name that is
			// already taken comes back as a question rather than a failure.
			if (!replace && error && error.field === 'template_exists') {
				if (window.confirm(l10n.templateConfirmReplace)) {
					await uploadTemplate(file, true)
					return
				}
				setStatus('', null, templateStatusNode)
				return
			}
			setStatus(error instanceof Error ? error.message : l10n.templateFailed, 'error', templateStatusNode)
		}
	}

	async function deleteTemplate(name) {
		try {
			const data = await postJson(templatesDeleteUrl, { name })
			setStatus(String(data.message || ''), 'success', templateStatusNode)
			await loadTemplates()
		} catch (error) {
			setStatus(error instanceof Error ? error.message : l10n.templateFailed, 'error', templateStatusNode)
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

	// One place decides what a response means, so a caller cannot forget to
	// look at the status or at `ok` in the body.
	async function readJsonResponse(response) {
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

	/**
	 * POST a JSON body. Used for the template upload, where URL-encoding a
	 * whole file would inflate it several times over.
	 */
	async function postJsonBody(url, payload) {
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: String(OC.requestToken || ''),
			},
			body: JSON.stringify(payload),
		})
		return readJsonResponse(response)
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

		return readJsonResponse(response)
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		clearFieldErrors()
		// Verdicts describe the values of the previous run: keeping them up
		// while this one is in flight puts a green tick next to a field that
		// is being changed, and a failed save would leave them there.
		renderConnectionChecks([])
		beginStatus(l10n.saving)
		try {
			const data = await postJson(saveUrl, getPayload())
			// Saving answers with the cookie verdict alone; rendering it clears
			// the other fields' results, which the save just invalidated.
			renderConnectionChecks(data.checks)
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
		renderConnectionChecks([])
		beginStatus(l10n.checking, connectionTarget)
		try {
			const data = await postJson(healthUrl, getPayload())
			if (typeof data.pending_delete_count !== 'undefined') {
				updatePendingDeleteUi(Number(data.pending_delete_count))
			}
			// The per-field results carry the target, pad count and latency, and
			// the protected-pads verdict, so the summary stays a summary.
			renderConnectionChecks(data.checks)
			const needsAttention = Array.isArray(data.checks)
				&& data.checks.some((check) => check && check.status === 'warning')
			setStatus(String(data.message), needsAttention ? 'warning' : 'success', connectionTarget)
		} catch (error) {
			if (error && typeof error.field === 'string' && error.field !== '') {
				showFieldError(error.field, error.message || l10n.healthFailed)
			}
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

	if (templateUploadButton instanceof HTMLElement && templateFileInput instanceof HTMLInputElement) {
		templateUploadButton.addEventListener('click', () => {
			templateFileInput.click()
		})
		templateFileInput.addEventListener('change', () => {
			const file = templateFileInput.files && templateFileInput.files[0]
			if (file) {
				void uploadTemplate(file)
			}
			// Reset so picking the same file twice fires again.
			templateFileInput.value = ''
		})
		void loadTemplates()
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
