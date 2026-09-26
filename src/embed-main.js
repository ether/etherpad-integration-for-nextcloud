/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { ocRequestToken } from './lib/oc-compat.js'
import { createPadSync } from './lib/pad-sync.js'
import { fetchJsonWithTimeout as fetchJson, requestErrorMessage } from './lib/fetch-helpers.js'
import { handFocusTo } from './lib/hand-focus.js'
import { loadPadContent } from './lib/pad-content.js'
import { assertOpenPayload, contentUrlFrom, contentViewFrom, isMissingBindingError, isRetryableOpenError, openWithFrontmatterRecovery, padUrlFrom, syncSettingsFrom } from './lib/pad-open-flow.js'

(function () {
	const IFRAME_REVEAL_DELAY_MS = 100
	const BUTTON_CLASS = 'epnc-embed__recovery-button'
	const PRIMARY_BUTTON_CLASS = BUTTON_CLASS + ' epnc-embed__recovery-button--primary'

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
	const errorActionsNode = root.querySelector('[data-epnc-embed-error-actions]')
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
	const unansweredText = String(root.getAttribute('data-l10n-unanswered') || 'Nextcloud did not answer. Check your connection and try again.').trim()
	let messageHandler = null

	const requestToken = () => ocRequestToken(templateRequestToken)
	const padSync = createPadSync({ requestToken })

	const messageOf = (error, fallback) => requestErrorMessage(error, unansweredText, fallback)
	// The page sits in another one, which must not scroll to it.
	const handFocusToCard = (actionsNode, messageNode) => handFocusTo(actionsNode, messageNode, { preventScroll: true })

	/** Every button of this page: the content's retry, the error panel's, the recovery card's. */
	const buildButton = (label, onClick, className = BUTTON_CLASS) => {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = className
		button.textContent = label
		button.addEventListener('click', onClick)
		return button
	}

	/**
	 * $canRetry: the open may work later (`isRetryableOpenError`), so the
	 * panel offers to run it again rather than a dead end. $afterClick: see
	 * handFocusTo().
	 */
	const showError = (message, canRetry = false, afterClick = false) => {
		hideAllPanels()
		if (errorMessageNode instanceof HTMLElement) {
			errorMessageNode.textContent = String(message || 'Unknown error.')
		}
		if (errorActionsNode instanceof HTMLElement) {
			errorActionsNode.replaceChildren()
			if (canRetry) {
				errorActionsNode.appendChild(buildButton(contentRetryText, () => restartOpen(), PRIMARY_BUTTON_CLASS))
			}
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = false
		}
		if (afterClick) {
			handFocusToCard(errorActionsNode, errorMessageNode)
		}
	}

	/** Every panel away and the loading state up, before a pad or a view shows. */
	const showLoading = () => {
		hideAllPanels()
		if (loadingNode instanceof HTMLElement) {
			loadingNode.classList.remove('epnc-embed__loading--pad-doc')
			loadingNode.hidden = false
		}
	}

	/**
	 * The read-only surface: a small toolbar and a document area that
	 * `loadContent` fills. Returns the parts the loader redraws, so the
	 * frame and its button survive a refresh.
	 */
	const showPadContentView = (url) => {
		showLoading()
		if (!(loadingNode instanceof HTMLElement)) {
			return null
		}
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
			renderContentError(view, contentUrl, messageOf(error, contentErrorText))
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
			body.appendChild(buildButton(contentRetryText, () => { void loadContent(view, contentUrl) }, 'button primary'))
		}
	}

	const showIframe = (url) => {
		if (!(iframe instanceof HTMLIFrameElement)) {
			showError('Embed iframe is not available.')
			return
		}
		// The loading state stays up until the pad has loaded.
		showLoading()
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
		return assertOpenPayload(data)
	}

	const initializePad = async () => {
		const url = initializeByIdUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		// A write: see fetchJsonWithTimeout() for why it gets no timeout.
		const data = await fetchJson(url, {
			method: 'POST',
			headers: {
				requesttoken: requestToken(),
			},
		}, { timeoutMs: null })
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

	const showRecoveryWithOriginal = (originalEmbedUrl, errorMessage) => {
		if (!(recoveryNode instanceof HTMLElement)) return
		hideAllPanels()
		recoveryNode.hidden = false
		if (recoveryMessageNode instanceof HTMLElement) recoveryMessageNode.textContent = errorMessage
		if (recoveryBodyNode instanceof HTMLElement) recoveryBodyNode.textContent = recoveryCopyBodyText
		if (recoveryActionsNode instanceof HTMLElement) {
			const openLink = document.createElement('a')
			openLink.className = PRIMARY_BUTTON_CLASS
			// Stay in embed mode: load the original's embed page in the same
			// frame so a host iframe doesn't need to deal with a new tab.
			openLink.href = originalEmbedUrl
			openLink.textContent = recoveryOpenOriginalText
			recoveryActionsNode.replaceChildren(
				openLink,
				buildButton(recoveryCreateNewText, () => { void triggerRecovery() }),
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
			recoveryActionsNode.replaceChildren(
				buildButton(recoveryCreateNewText, () => { void triggerRecovery() }, PRIMARY_BUTTON_CLASS),
			)
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
			// A write, like initializePad().
			await fetchJson(url, {
				method: 'POST',
				headers: { requesttoken: requestToken() },
			}, { timeoutMs: null })
			// Restart the open flow now that the binding exists.
			restartOpen()
		} catch (error) {
			// No answer: the pad may be set up by now, and another recovery
			// would meet it. Opening tells, and is safe to repeat.
			if (error && error.unanswered === true) {
				restartOpen()
				return
			}
			setRecoveryActionsBusy(false)
			if (recoveryMessageNode instanceof HTMLElement) {
				recoveryMessageNode.textContent = messageOf(error, 'Recovery failed.')
			}
			// The clicked button lost the focus while it was disabled.
			handFocusToCard(recoveryActionsNode, recoveryMessageNode)
		}
	}

	/** The original's embed page, or '' when there is none or no answer. */
	const findOriginalEmbedUrl = async () => {
		if (findOriginalUrlTemplate === '') {
			return ''
		}
		const lookupUrl = findOriginalUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		try {
			const hint = await fetchJson(lookupUrl, { method: 'GET' })
			if (hint && hint.found === true && typeof hint.embed_url === 'string') {
				return hint.embed_url
			}
		} catch {
			// Silent: the card then offers a new pad only.
		}
		return ''
	}

	const enterRecoveryFlow = async (initialError, afterClick, isCurrent) => {
		const errorMessage = messageOf(initialError, 'Pad open failed.')
		showRecoveryChecking()
		const originalEmbedUrl = await findOriginalEmbedUrl()
		if (!isCurrent()) {
			return
		}
		if (originalEmbedUrl !== '') {
			showRecoveryWithOriginal(originalEmbedUrl, errorMessage)
		} else {
			showRecoveryWithoutOriginal(errorMessage)
		}
		if (afterClick) {
			handFocusToCard(recoveryActionsNode, recoveryMessageNode)
		}
	}

	/**
	 * The open again, from the loading state: after a recovery made the
	 * binding, or as a second try. Either follows a click.
	 */
	const restartOpen = () => {
		showLoading()
		void run(true)
	}

	// So an open that answers late cannot undo a newer one.
	let openGeneration = 0

	const run = async (afterClick = false) => {
		openGeneration += 1
		const generation = openGeneration
		const isCurrent = () => generation === openGeneration
		if (!Number.isFinite(fileId) || fileId <= 0 || openByIdUrl === '' || initializeByIdUrlTemplate === '') {
			showError('Embed configuration is incomplete.')
			return
		}
		if (requestToken() === '') {
			showError('CSRF request token is missing.')
			return
		}
		try {
			const data = await openWithFrontmatterRecovery({ open: openPad, initialize: initializePad, stillWanted: isCurrent })
			if (data === null || !isCurrent()) {
				return
			}
			const { syncUrl, intervalMs } = syncSettingsFrom(data)
			padSync.configure({ syncUrl, intervalMs })
			padSync.installLifecycleHandlers()
			installHostMessageHandler()
			// No syncing for a viewer: it writes the pad back into the .pad
			// file, which is exactly what a read-only share may not do.
			if (syncUrl !== '') {
				padSync.start()
			}
			const contentUrl = contentUrlFrom(data)
			const { isContentView, externalUrl } = contentViewFrom(data)
			if (isContentView) {
				const view = showPadContentView(externalUrl)
				if (view !== null) {
					view.refresh.addEventListener('click', () => { void loadContent(view, contentUrl) })
				}
				void loadContent(view, contentUrl)
				return
			}
			showIframe(padUrlFrom(data))
		} catch (error) {
			if (!isCurrent()) {
				return
			}
			if (isMissingBindingError(error)) {
				void enterRecoveryFlow(error, afterClick, isCurrent)
				return
			}
			showError(messageOf(error, 'Pad open failed.'), isRetryableOpenError(error), afterClick)
		}
	}

	void run()
})()
