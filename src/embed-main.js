/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { ocRequestToken } from './lib/oc-compat.js'
import { createPadSync } from './lib/pad-sync.js'
import { fetchJsonWithTimeout as fetchJson } from './lib/fetch-helpers.js'
import { loadPadContent } from './lib/pad-content.js'

(function () {
	const IFRAME_REVEAL_DELAY_MS = 100

	const root = document.getElementById('etherpad-nextcloud-embed')
	if (!(root instanceof HTMLElement)) {
		return
	}

	const fileId = Number(root.getAttribute('data-file-id') || '')
	const openByIdUrl = String(root.getAttribute('data-open-by-id-url') || '').trim()
	const initializeByIdUrlTemplate = String(root.getAttribute('data-initialize-by-id-url-template') || '').trim()
	const recoverUrlTemplate = String(root.getAttribute('data-recover-url-template') || '').trim()
	const findOriginalUrlTemplate = String(root.getAttribute('data-find-original-url-template') || '').trim()
	const templateRequestToken = String(root.getAttribute('data-request-token') || '').trim()
	const trustedOrigins = String(root.getAttribute('data-trusted-origins') || '')
		.split(/\s+/)
		.map((value) => value.trim())
		.filter(Boolean)
	const loadingNode = root.querySelector('[data-epnc-embed-loading]')
	const errorNode = root.querySelector('[data-epnc-embed-error]')
	const errorMessageNode = root.querySelector('[data-epnc-embed-error-message]')
	const recoveryNode = root.querySelector('[data-epnc-embed-recovery]')
	const recoveryMessageNode = root.querySelector('[data-epnc-embed-recovery-message]')
	const recoveryBodyNode = root.querySelector('[data-epnc-embed-recovery-body]')
	const recoveryActionsNode = root.querySelector('[data-epnc-embed-recovery-actions]')
	const iframe = root.querySelector('[data-epnc-embed-iframe]')
	const contentEmptyText = String(root.getAttribute('data-l10n-content-empty') || 'This pad is still empty.').trim()
	const contentLoadingText = String(root.getAttribute('data-l10n-content-loading') || 'Loading pad content...').trim()
	const contentErrorText = String(root.getAttribute('data-l10n-content-error') || 'Could not load the pad content.').trim()
	const contentNoUrlText = String(root.getAttribute('data-l10n-content-no-url') || 'The server did not say where to load this pad from.').trim()
	const contentRetryText = String(root.getAttribute('data-l10n-content-retry') || 'Try again').trim()
	const contentRefreshText = String(root.getAttribute('data-l10n-content-refresh') || 'Refresh').trim()
	const contentRefreshingText = String(root.getAttribute('data-l10n-content-refreshing') || 'Refreshing...').trim()
	const externalLinkText = String(root.getAttribute('data-l10n-external-link') || 'Open original pad').trim()
	const recoveryCheckingText = String(root.getAttribute('data-l10n-recovery-checking') || 'Checking for the original pad...').trim()
	const recoveryCopyBodyText = String(root.getAttribute('data-l10n-recovery-copy-body') || '').trim()
	const recoveryOrphanBodyText = String(root.getAttribute('data-l10n-recovery-orphan-body') || '').trim()
	const recoveryOpenOriginalText = String(root.getAttribute('data-l10n-recovery-open-original') || 'Open the original .pad file').trim()
	const recoveryCreateNewText = String(root.getAttribute('data-l10n-recovery-create-new') || 'Create new pad from this file').trim()
	const recoveryCreatingText = String(root.getAttribute('data-l10n-recovery-creating') || 'Creating new pad...').trim()
	let messageHandler = null

	const requestToken = () => ocRequestToken(templateRequestToken)
	const padSync = createPadSync({ requestToken })

	const showError = (message) => {
		if (loadingNode instanceof HTMLElement) {
			loadingNode.hidden = true
			loadingNode.classList.remove('epnc-embed__loading--pad-doc')
		}
		if (iframe instanceof HTMLIFrameElement) {
			iframe.hidden = true
			iframe.removeAttribute('src')
		}
		if (errorMessageNode instanceof HTMLElement) {
			errorMessageNode.textContent = String(message || 'Unknown error.')
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = false
		}
	}

	/**
	 * The read-only surface: a small toolbar and a document area that
	 * `loadContent` fills. Returns the parts the loader redraws, so the
	 * frame and its button survive a refresh.
	 */
	const showPadContentView = (url) => {
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = true
		}
		if (iframe instanceof HTMLIFrameElement) {
			iframe.hidden = true
			iframe.removeAttribute('src')
		}
		if (!(loadingNode instanceof HTMLElement)) {
			return null
		}
		loadingNode.hidden = false
		loadingNode.classList.add('epnc-embed__loading--pad-doc')
		loadingNode.textContent = ''

		const surface = document.createElement('div')
		surface.className = 'epnc-pad-doc'

		const inner = document.createElement('div')
		inner.className = 'epnc-pad-doc__inner'

		const toolbar = document.createElement('div')
		toolbar.className = 'epnc-pad-doc__toolbar'

		// A failed refresh is reported here rather than in place of the pad.
		const toolbarError = document.createElement('span')
		toolbarError.className = 'epnc-pad-doc__toolbar-error'
		toolbarError.hidden = true
		toolbar.appendChild(toolbarError)

		const refresh = document.createElement('button')
		refresh.type = 'button'
		refresh.className = 'button epnc-pad-doc__refresh'
		refresh.textContent = contentRefreshText
		toolbar.appendChild(refresh)

		// A read-only share has nothing to link to: the pad it would point
		// at is the one being withheld.
		if (String(url || '').trim() !== '') {
			const link = document.createElement('a')
			link.className = 'button epnc-pad-doc__link'
			link.href = url
			link.target = '_blank'
			link.rel = 'noopener noreferrer'
			link.textContent = externalLinkText
			toolbar.appendChild(link)
		}

		const body = document.createElement('div')
		body.className = 'epnc-pad-doc__text'
		body.textContent = contentLoadingText

		inner.appendChild(toolbar)
		inner.appendChild(body)
		surface.appendChild(inner)
		loadingNode.appendChild(surface)

		// `loaded`: "busy" and "nothing yet" are two states, and only the
		// second may blank the view.
		return { body, refresh, toolbarError, loaded: false }
	}

	// So a slower earlier answer cannot land on top of a newer one.
	let contentGeneration = 0

	/** Each call re-checks access on the server. */
	const loadContent = async (view, contentUrl) => {
		if (!view || !(view.body instanceof HTMLElement)) {
			return
		}
		const { body, refresh, toolbarError } = view
		contentGeneration += 1
		const generation = contentGeneration
		const isCurrent = () => generation === contentGeneration

		// Only before the first answer; after that the button carries it.
		if (!view.loaded) {
			body.className = 'epnc-pad-doc__text'
			body.textContent = contentLoadingText
		}
		if (toolbarError instanceof HTMLElement) {
			toolbarError.hidden = true
		}
		if (refresh instanceof HTMLButtonElement) {
			refresh.disabled = true
			refresh.textContent = contentRefreshingText
		}

		try {
			if (contentUrl === '') {
				// Retrying cannot help, so the message says what is actually
				// wrong instead of inviting another press.
				renderContentError(view, contentUrl, contentNoUrlText, false)
				return
			}
			const content = await loadPadContent(contentUrl)
			if (!isCurrent()) return
			view.loaded = true
			if (content.isEmpty) {
				// Left blank this would read as a failure nobody reported.
				body.className = 'epnc-pad-doc__text'
				body.textContent = contentEmptyText
				return
			}
			body.className = 'epnc-pad-doc__text epnc-pad-doc__text--html'
			body.innerHTML = content.html
		} catch (error) {
			if (!isCurrent()) return
			renderContentError(view, contentUrl, error instanceof Error ? error.message : contentErrorText)
		} finally {
			if (isCurrent() && refresh instanceof HTMLButtonElement) {
				refresh.disabled = false
				refresh.textContent = contentRefreshText
			}
		}
	}

	const renderContentError = (view, contentUrl, message, canRetry = true) => {
		const { body, toolbarError } = view
		// Something is on screen: keep it, and say the attempt failed.
		if (view.loaded && toolbarError instanceof HTMLElement) {
			toolbarError.textContent = message || contentErrorText
			toolbarError.hidden = false
			return
		}
		body.className = 'epnc-pad-doc__text'
		body.textContent = ''

		const text = document.createElement('p')
		text.textContent = message || contentErrorText

		body.appendChild(text)
		if (canRetry) {
			const retry = document.createElement('button')
			retry.type = 'button'
			retry.className = 'button primary'
			retry.textContent = contentRetryText
			retry.addEventListener('click', () => { void loadContent(view, contentUrl) })
			body.appendChild(retry)
		}
	}

	const showIframe = (url) => {
		if (!(iframe instanceof HTMLIFrameElement)) {
			showError('Embed iframe is not available.')
			return
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = true
		}
		if (loadingNode instanceof HTMLElement) {
			loadingNode.classList.remove('epnc-embed__loading--pad-doc')
		}
		iframe.hidden = true
		const revealIframe = () => {
			iframe.removeEventListener('load', revealIframe)
			window.setTimeout(() => {
				if (loadingNode instanceof HTMLElement) {
					loadingNode.hidden = true
				}
				iframe.hidden = false
			}, IFRAME_REVEAL_DELAY_MS)
		}
		iframe.addEventListener('load', revealIframe, { once: true })
		iframe.src = url
	}

	const postHostMessage = (source, origin, type, payload = {}) => {
		// Replies are only sent from the already origin-validated message handler.
		if (!source || typeof source.postMessage !== 'function') {
			return
		}
		source.postMessage(Object.assign({
			type,
			fileId,
		}, payload), origin)
	}

	const isAllowedMessageOrigin = (origin) => {
		if (!origin || origin === 'null') {
			return false
		}
		if (origin === window.location.origin) {
			return true
		}
		return trustedOrigins.includes(origin)
	}

	const installHostMessageHandler = () => {
		if (messageHandler) {
			return
		}
		messageHandler = (event) => {
			const origin = String(event.origin || '')
			if (!isAllowedMessageOrigin(origin)) {
				return
			}
			const payload = event.data
			const type = typeof payload === 'string'
				? payload
				: (payload && typeof payload === 'object' && typeof payload.type === 'string' ? payload.type : '')
			if (!type) {
				return
			}
			if (type === 'epnc:host-visible') {
				padSync.start()
				return
			}
			if (type === 'epnc:host-hidden') {
				padSync.fireAndForget(true, true)
				padSync.stop()
				return
			}
			if (type === 'epnc:host-before-close' || type === 'epnc:host-sync-now') {
				const keepalive = type !== 'epnc:host-sync-now'
				const reason = type === 'epnc:host-before-close' ? 'before-close' : 'sync-now'
				postHostMessage(event.source, origin, 'epnc:sync-flush-started', {
					reason,
				})
				void padSync.sync(true, keepalive)
					.then((result) => {
						postHostMessage(event.source, origin, 'epnc:sync-flush-finished', {
							reason,
							result: result && typeof result === 'object' ? result : {},
						})
					})
					.catch((error) => {
						postHostMessage(event.source, origin, 'epnc:sync-flush-failed', {
							reason,
							message: error instanceof Error ? error.message : 'Sync failed.',
						})
					})
				if (keepalive) {
					padSync.stop()
				}
			}
		}
		window.addEventListener('message', messageHandler)
	}

	const isMissingFrontmatterError = (error) => {
		if (!(error instanceof Error)) {
			return false
		}
		return String(error.message || '').includes('Missing YAML frontmatter')
	}

	const openPad = async () => {
		const body = new URLSearchParams()
		body.set('fileId', String(fileId))
		const data = await fetchJson(openByIdUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: requestToken(),
			},
			body: body.toString(),
		})
		if (!data || (data.is_readonly_view !== true && (typeof data.url !== 'string' || data.url.trim() === ''))) {
			throw new Error('Pad open API did not return a valid URL.')
		}
		return data
	}

	const initializePad = async () => {
		const url = initializeByIdUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		const data = await fetchJson(url, {
			method: 'POST',
			headers: {
				requesttoken: requestToken(),
			},
		})
		if (data && data.status === 'migrated_from_legacy') {
			// Mirror the backend audit-log entry to the browser console; no
			// toast surface is wired up in this app yet.
			console.info('Legacy Ownpad .pad migrated to managed format on first open.')
		}
	}

	const hideAllPanels = () => {
		if (loadingNode instanceof HTMLElement) loadingNode.hidden = true
		if (errorNode instanceof HTMLElement) errorNode.hidden = true
		if (recoveryNode instanceof HTMLElement) recoveryNode.hidden = true
		if (iframe instanceof HTMLIFrameElement) {
			iframe.hidden = true
			iframe.removeAttribute('src')
		}
	}

	const showRecoveryChecking = () => {
		if (!(recoveryNode instanceof HTMLElement)) return
		hideAllPanels()
		recoveryNode.hidden = false
		if (recoveryMessageNode instanceof HTMLElement) recoveryMessageNode.textContent = recoveryCheckingText
		if (recoveryBodyNode instanceof HTMLElement) recoveryBodyNode.textContent = ''
		if (recoveryActionsNode instanceof HTMLElement) recoveryActionsNode.replaceChildren()
	}

	const buildRecoveryButton = (label, onClick) => {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = 'epnc-embed__recovery-button'
		button.textContent = label
		button.addEventListener('click', onClick)
		return button
	}

	const showRecoveryWithOriginal = (originalEmbedUrl, errorMessage) => {
		if (!(recoveryNode instanceof HTMLElement)) return
		hideAllPanels()
		recoveryNode.hidden = false
		if (recoveryMessageNode instanceof HTMLElement) recoveryMessageNode.textContent = errorMessage
		if (recoveryBodyNode instanceof HTMLElement) recoveryBodyNode.textContent = recoveryCopyBodyText
		if (recoveryActionsNode instanceof HTMLElement) {
			const openLink = document.createElement('a')
			openLink.className = 'epnc-embed__recovery-button epnc-embed__recovery-button--primary'
			// Stay in embed mode: load the original's embed page in the same
			// frame so a host iframe doesn't need to deal with a new tab.
			openLink.href = originalEmbedUrl
			openLink.textContent = recoveryOpenOriginalText
			recoveryActionsNode.replaceChildren(
				openLink,
				buildRecoveryButton(recoveryCreateNewText, () => { void triggerRecovery() }),
			)
		}
	}

	const showRecoveryWithoutOriginal = (errorMessage) => {
		if (!(recoveryNode instanceof HTMLElement)) return
		hideAllPanels()
		recoveryNode.hidden = false
		if (recoveryMessageNode instanceof HTMLElement) recoveryMessageNode.textContent = errorMessage
		if (recoveryBodyNode instanceof HTMLElement) recoveryBodyNode.textContent = recoveryOrphanBodyText
		if (recoveryActionsNode instanceof HTMLElement) {
			const button = buildRecoveryButton(recoveryCreateNewText, () => { void triggerRecovery() })
			button.classList.add('epnc-embed__recovery-button--primary')
			recoveryActionsNode.replaceChildren(button)
		}
	}

	const setRecoveryActionsBusy = (busy) => {
		if (!(recoveryActionsNode instanceof HTMLElement)) return
		const buttons = recoveryActionsNode.querySelectorAll('button')
		buttons.forEach((node) => {
			node.disabled = busy
			if (busy) {
				node.dataset.originalLabel = node.dataset.originalLabel || node.textContent || ''
				node.textContent = recoveryCreatingText
			} else if (node.dataset.originalLabel) {
				node.textContent = node.dataset.originalLabel
				delete node.dataset.originalLabel
			}
		})
	}

	const triggerRecovery = async () => {
		if (recoverUrlTemplate === '') {
			showError('Recovery is not available in this embed.')
			return
		}
		setRecoveryActionsBusy(true)
		const url = recoverUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		try {
			await fetchJson(url, {
				method: 'POST',
				headers: { requesttoken: requestToken() },
			})
			// Restart the open flow now that the binding exists.
			hideAllPanels()
			if (loadingNode instanceof HTMLElement) loadingNode.hidden = false
			void run()
		} catch (error) {
			setRecoveryActionsBusy(false)
			if (recoveryMessageNode instanceof HTMLElement) {
				recoveryMessageNode.textContent = error instanceof Error && error.message
					? error.message
					: 'Recovery failed.'
			}
		}
	}

	const enterRecoveryFlow = async (initialError) => {
		const errorMessage = initialError instanceof Error && initialError.message
			? initialError.message
			: 'Pad open failed.'
		showRecoveryChecking()
		if (findOriginalUrlTemplate === '') {
			showRecoveryWithoutOriginal(errorMessage)
			return
		}
		const lookupUrl = findOriginalUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		try {
			const hint = await fetchJson(lookupUrl, { method: 'GET' })
			if (hint && hint.found === true && typeof hint.embed_url === 'string' && hint.embed_url !== '') {
				showRecoveryWithOriginal(hint.embed_url, errorMessage)
				return
			}
		} catch {
			// Silent: fall through to the no-match branch.
		}
		showRecoveryWithoutOriginal(errorMessage)
	}

	const run = async () => {
		if (!Number.isFinite(fileId) || fileId <= 0 || openByIdUrl === '' || initializeByIdUrlTemplate === '') {
			showError('Embed configuration is incomplete.')
			return
		}
		if (requestToken() === '') {
			showError('CSRF request token is missing.')
			return
		}
		try {
			let data
			try {
				data = await openPad()
			} catch (error) {
				if (!isMissingFrontmatterError(error)) {
					throw error
				}
				await initializePad()
				data = await openPad()
			}
			const syncUrl = typeof data.sync_url === 'string' ? data.sync_url.trim() : ''
			const intervalSeconds = Number(data.sync_interval_seconds ?? 0)
			const intervalMs = Number.isFinite(intervalSeconds) && intervalSeconds > 0 ? intervalSeconds * 1000 : 120000
			padSync.configure({ syncUrl, intervalMs })
			padSync.installLifecycleHandlers()
			installHostMessageHandler()
			// No syncing for a viewer: it writes the pad back into the .pad
			// file, which is exactly what a read-only share may not do.
			if (syncUrl !== '') {
				padSync.start()
			}
			const contentUrl = typeof data.content_url === 'string' ? data.content_url.trim() : ''
			if (data.is_readonly_view === true || data.is_external === true) {
				const view = showPadContentView(data.is_readonly_view === true ? '' : data.url)
				if (view !== null) {
					view.refresh.addEventListener('click', () => { void loadContent(view, contentUrl) })
				}
				void loadContent(view, contentUrl)
				return
			}
			showIframe(data.url)
		} catch (error) {
			if (error && error.code === 'missing_binding') {
				void enterRecoveryFlow(error)
				return
			}
			showError(error instanceof Error ? error.message : 'Pad open failed.')
		}
	}

	void run()
})()
