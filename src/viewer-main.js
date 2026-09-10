/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID } from './lib/constants.js'
import { apiFindOriginalPad, apiRecoverFromSnapshot, apiResolvePadByPath } from './lib/api-client.js'
import { fetchJsonWithTimeout } from './lib/fetch-helpers.js'
import { ocGenerateUrl, ocRequestToken, translate } from './lib/oc-compat.js'
import { createPadSync } from './lib/pad-sync.js'
import { loadPadContent } from './lib/pad-content.js'
import { assertOpenPayload, contentUrlFrom, contentViewFrom, openWithFrontmatterRecovery, padUrlFrom, syncSettingsFrom } from './lib/pad-open-flow.js'
import { buildPadFrameSrcdoc } from './lib/pad-frame-srcdoc.js'
import { isPadName, parsePadPathFromDavHref, parsePublicShareTokenFromLocation } from './lib/urls.js'

const component = {
	name: 'EtherpadNextcloudViewer',
	props: {
		filename: { type: String, required: false, default: '' },
		basename: { type: String, required: false, default: '' },
		source: { type: String, required: false, default: '' },
		fileid: { type: [String, Number], required: false, default: null },
		fileId: { type: [String, Number], required: false, default: null },
		fileInfo: { type: Object, required: false, default: null },
	},
	data() {
		return {
			iframeSrc: '',
			isLoading: true,
			loadError: '',
			canRecover: false,
			maybeStaleFileId: false,
			// Recovery may resolve this from the path when Viewer supplies no id.
			recoveryFileId: null,
			// Kept with the id so recovery invalidates the matching cache entry.
			recoveryPath: '',
			isRecovering: false,
			isCheckingOriginal: false,
			originalPad: null,
			externalOpenUrl: '',
			contentMode: '',
			contentUrl: '',
			contentState: 'idle',
			contentError: '',
			content: { html: '', isEmpty: false },
			// A refresh keeps previously loaded content visible.
			contentLoaded: false,
			// Refreshes supersede each other without superseding the open.
			contentGeneration: 0,
			resolveGeneration: 0,
		}
	},
	computed: {
		sourcePath() {
			// Whitespace inside the DAV URL is encoded; only surrounding noise is trimmed.
			const value = typeof this.source === 'string' ? this.source.trim() : ''
			if (!value) return ''
			return parsePadPathFromDavHref(value) || ''
		},
		filePath() {
			// Preserve valid filename and directory whitespace when opening a file.
			const normalizeDir = (dir) => {
				if (!dir || dir === '/') return '/'
				return dir.startsWith('/') ? dir : ('/' + dir)
			}
			const joinPath = (dir, name) => {
				if (!name) return ''
				if (name.startsWith('/')) return name
				const normalizedDir = normalizeDir(dir)
				return normalizedDir === '/' ? '/' + name : normalizedDir + '/' + name
			}
			if (isPadName(this.sourcePath)) return this.sourcePath

			const info = this.fileInfo && typeof this.fileInfo === 'object' ? this.fileInfo : null
			const infoPath = info && typeof info.path === 'string' ? info.path : ''
			if (isPadName(infoPath)) return infoPath.startsWith('/') ? infoPath : ('/' + infoPath)

			const baseName = String(this.filename || this.basename || (info && (info.name || info.basename)) || '')
			if (!baseName) return ''

			const infoDir = info && typeof info.dirname === 'string' ? info.dirname : ''
			if (infoDir) {
				const combined = joinPath(infoDir, baseName)
				if (isPadName(combined)) return combined
			}

			const params = new URLSearchParams(window.location.search || '')
			const urlDir = params.get('dir') || '/'
			const fromDir = joinPath(urlDir, baseName)
			if (isPadName(fromDir)) return fromDir
			return '/' + baseName
		},
		openKey() {
			return `${this.resolvedFileId === null ? '' : this.resolvedFileId}::${this.filePath}`
		},
		resolvedFileId() {
			const candidates = [this.fileid, this.fileId, this.fileInfo && (this.fileInfo.fileid || this.fileInfo.fileId || this.fileInfo.id)]
			for (const candidate of candidates) {
				const numeric = Number(candidate)
				if (Number.isFinite(numeric) && numeric > 0) return numeric
			}
			// Route ids can outlive the item shown after Viewer navigation.
			return null
		},
	},
	watch: {
		// A file swap changes path and id together; open it only once.
		openKey: { immediate: true, handler() { void this.resolveOpenUrl() } },
	},
	methods: {
		async fetchOpenPayload(url, init = {}) {
			return assertOpenPayload(await fetchJsonWithTimeout(url, Object.assign({ method: 'GET' }, init)))
		},
		async initializeMissingFrontmatter() {
			const headers = {
				Accept: 'application/json',
				requesttoken: ocRequestToken(),
			}

			// A client timeout would not stop the server-side provisioning work.
			const initOptions = { timeoutMs: null, fallbackMessage: 'Pad initialization failed.' }

			const announceMigratedStatus = (data) => {
				if (data && data.status === 'migrated_from_legacy') {
					console.info('Legacy Ownpad .pad migrated to managed format on first open.')
				}
			}

			if (this.resolvedFileId !== null) {
				const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize-by-id/' + encodeURIComponent(String(this.resolvedFileId)))
				const data = await fetchJsonWithTimeout(url, { method: 'POST', headers }, initOptions)
				announceMigratedStatus(data)
				return data
			}

			if (!this.filePath) {
				throw new Error('Pad initialization failed: missing file path.')
			}

			const body = new URLSearchParams()
			body.set('file', this.filePath)
			const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize')
			const data = await fetchJsonWithTimeout(url, {
				method: 'POST',
				headers: Object.assign({}, headers, {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				}),
				body: body.toString(),
			}, initOptions)
			announceMigratedStatus(data)
			return data
		},
		/** Resolve a fresh id for recovery without changing the opened file. */
		async resolveRecoveryFileId(openPath) {
			try {
				const resolved = await apiResolvePadByPath(openPath, { bypassCache: true })
				const id = Number(resolved && resolved.file_id)
				return Number.isFinite(id) && id > 0 ? id : null
			} catch {
				return null
			}
		},
		markLoaded() {
			this.$emit('update:loaded', true)
		},
		// Keep the sync controller non-reactive and available to the immediate watcher.
		padSync() {
			if (!this._padSync) {
				this._padSync = createPadSync({ requestToken: () => ocRequestToken() })
			}
			return this._padSync
		},
		// Do not create a controller solely to tear it down.
		teardownSync() {
			if (!this._padSync) {
				return
			}
			this._padSync.fireAndForget(true, true)
			this._padSync.stop()
			this._padSync.removeLifecycleHandlers()
		},
		async resolveOpenUrl() {
			const generation = ++this.resolveGeneration
			const isCurrent = () => generation === this.resolveGeneration
			// Discarding a result is insufficient: a completed request may mint a session.
			this._openAbort?.abort()
			const abort = typeof AbortController === 'function' ? new AbortController() : null
			this._openAbort = abort

			this.isLoading = true
			this.loadError = ''
			this.canRecover = false
			this.maybeStaleFileId = false
			this.recoveryFileId = null
			this.recoveryPath = ''
			this.isCheckingOriginal = false
			this.originalPad = null
			this.iframeSrc = ''
			this.externalOpenUrl = ''
			this.contentMode = ''
			this.contentUrl = ''
			this.contentState = 'idle'
			this.contentError = ''
			this.content = { html: '', isEmpty: false }
			this.contentLoaded = false
			this._contentAbort?.abort()
			// Do not construct a sync controller while resetting viewer state.
			if (this._padSync) {
				this._padSync.stop()
				this._padSync.configure({ syncUrl: '' })
			}

			if (!this.filePath) {
				if (!isCurrent()) return
				this.loadError = 'No .pad file selected.'
				this.isLoading = false
				return
			}

			// Viewer props may change before the watcher starts the next open.
			const openPath = this.filePath
			const publicToken = parsePublicShareTokenFromLocation()
			const byPublicUrl = (() => {
				if (!publicToken) return ''
				const url = new URL(ocGenerateUrl('/apps/' + APP_ID + '/api/v1/public/open/' + encodeURIComponent(publicToken)), window.location.origin)
				// The public endpoint rejects conflicting id and path locators.
				if (this.resolvedFileId !== null) {
					url.searchParams.set('fileId', String(this.resolvedFileId))
				} else {
					url.searchParams.set('file', openPath)
				}
				return url.toString()
			})()
			const openPostHeaders = {
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: ocRequestToken(),
			}

			try {
				const fetchOpenData = async () => {
					// Never retry a refused id by path: it could identify a different file.
					const signal = abort ? abort.signal : undefined
					if (byPublicUrl) {
						return await this.fetchOpenPayload(byPublicUrl, { signal })
					}
					if (this.resolvedFileId !== null) {
						const byIdBody = new URLSearchParams()
						byIdBody.set('fileId', String(this.resolvedFileId))
						return await this.fetchOpenPayload(
							ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open-by-id'),
							{ method: 'POST', headers: openPostHeaders, body: byIdBody.toString(), signal },
						)
					}
					const byPathBody = new URLSearchParams()
					byPathBody.set('file', openPath)
					return await this.fetchOpenPayload(
						ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open'),
						{ method: 'POST', headers: openPostHeaders, body: byPathBody.toString(), signal },
					)
				}

				const data = await openWithFrontmatterRecovery({
					open: fetchOpenData,
					initialize: () => this.initializeMissingFrontmatter(),
					stillWanted: isCurrent,
				})
				if (data === null || !isCurrent()) return

				const { syncUrl, intervalMs } = syncSettingsFrom(data)

				this.padSync().configure({ syncUrl, intervalMs })
				this.padSync().installLifecycleHandlers()
				if (syncUrl) {
					this.padSync().start()
				}

				const contentUrl = contentUrlFrom(data)

				const { isContentView, externalUrl } = contentViewFrom(data)
				if (isContentView) {
					this.externalOpenUrl = externalUrl
					this.contentUrl = contentUrl
					this.contentMode = 'content'
					this.markLoaded()
					// Draw the content view while its body loads.
					void this.loadContent()
					return
				}

				this.iframeSrc = padUrlFrom(data)
				this.markLoaded()
			} catch (error) {
				if (!isCurrent()) return
				this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
				// The server intentionally does not disclose why this id is unavailable.
				this.maybeStaleFileId = this.resolvedFileId !== null
					&& Boolean(error) && error.status === 404 && !error.code
				// Recovery may resolve only the same path that failed to open.
				let recoveryFileId = this.resolvedFileId
				this.recoveryPath = openPath
				if (recoveryFileId === null && !byPublicUrl && error && error.code === 'missing_binding') {
					recoveryFileId = await this.resolveRecoveryFileId(openPath)
					// A late lookup must not attach recovery to a newer Viewer item.
					if (!isCurrent()) return
				}
				this.recoveryFileId = recoveryFileId
				this.canRecover = Boolean(error && error.code === 'missing_binding')
					&& recoveryFileId !== null
					&& !byPublicUrl
				if (this.canRecover) {
					this.fetchOriginalPadHint(isCurrent)
				}
				this.markLoaded()
			} finally {
				if (!isCurrent()) return
				this.isLoading = false
			}
		},
		async fetchOriginalPadHint(isCurrent) {
			if (this.recoveryFileId === null) {
				return
			}
			this.isCheckingOriginal = true
			try {
				const hint = await apiFindOriginalPad(this.recoveryFileId)
				if (!isCurrent()) return
				if (hint && hint.found === true && typeof hint.viewer_url === 'string' && hint.viewer_url !== '') {
					this.originalPad = {
						viewerUrl: hint.viewer_url,
						path: typeof hint.path === 'string' ? hint.path : '',
					}
				}
			} catch {
				// Recovery remains available without an original-file hint.
			} finally {
				if (isCurrent()) {
					this.isCheckingOriginal = false
				}
			}
		},
		async recoverFromSnapshot() {
			if (!this.canRecover || this.isRecovering || this.recoveryFileId === null) {
				return
			}
			this.isRecovering = true
			try {
				await apiRecoverFromSnapshot(this.recoveryFileId, this.recoveryPath)
				this.loadError = ''
				this.canRecover = false
				await this.resolveOpenUrl()
			} catch (error) {
				this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
			} finally {
				this.isRecovering = false
			}
		},
		/** Refresh content through an endpoint that re-checks access. */
		async loadContent() {
			const openGeneration = this.resolveGeneration
			this.contentGeneration += 1
			const generation = this.contentGeneration
			const isCurrent = () => generation === this.contentGeneration
				&& openGeneration === this.resolveGeneration

			if (this.contentUrl === '') {
				this.contentState = 'error'
				this.contentError = translate('The server did not say where to load this pad from.')
				return
			}

			this._contentAbort?.abort()
			// Abort superseded content refreshes.
			const abort = typeof AbortController === 'function' ? new AbortController() : null
			this._contentAbort = abort
			this.contentState = 'loading'
			this.contentError = ''

			try {
				const content = await loadPadContent(this.contentUrl, { signal: abort?.signal })
				if (!isCurrent()) return
				this.content = content
				this.contentLoaded = true
				this.contentState = 'ready'
			} catch (error) {
				if (!isCurrent() || (error && error.name === 'AbortError')) return
				this.contentState = 'error'
				this.contentError = error instanceof Error ? error.message : translate('Could not load the pad content.')
			}
		},
		renderContentView(createElement, options) {
			const extraActions = Array.isArray(options.actions) ? options.actions : []
			const isBusy = this.contentState === 'loading'

			return createElement('div', { class: 'epnc-pad-doc' }, [
				createElement('div', { class: 'epnc-pad-doc__inner' }, [
					createElement('div', { class: 'epnc-pad-doc__toolbar' }, [
						(this.contentState === 'error' && this.contentLoaded)
							? createElement('span', { class: 'epnc-pad-doc__toolbar-error' },
								this.contentError || translate('Could not load the pad content.'))
							: null,
						createElement('button', {
							class: 'button epnc-pad-doc__refresh',
							attrs: { type: 'button', disabled: isBusy },
							on: { click: () => { void this.loadContent() } },
						}, isBusy ? translate('Refreshing...') : translate('Refresh')),
						...extraActions,
					]),
					this.renderContentBody(createElement),
				]),
			])
		},
		renderContentBody(createElement) {
			if (this.contentLoaded && this.contentState !== 'ready') {
				return this.renderContentText(createElement)
			}
			if (this.contentState === 'error') {
				return createElement('div', { class: 'epnc-pad-doc__text epnc-pad-doc__status' }, [
					createElement('div', {},
						this.contentError || translate('Could not load the pad content.')),
					createElement('button', {
						class: 'button primary',
						attrs: { type: 'button' },
						on: { click: () => { void this.loadContent() } },
					}, translate('Try again')),
				])
			}
			if (this.contentState !== 'ready') {
				return createElement('div', { class: 'epnc-pad-doc__text epnc-pad-doc__status' }, translate('Loading pad content...'))
			}
			return this.renderContentText(createElement)
		},
		renderContentText(createElement) {
			if (this.content.isEmpty) {
				return createElement('div', { class: 'epnc-pad-doc__text epnc-pad-doc__status' }, translate('This pad is still empty.'))
			}
			return createElement('div', {
				class: 'epnc-pad-doc__text epnc-pad-doc__text--html',
				domProps: { innerHTML: this.content.html },
			})
		},
	},
	beforeDestroy() {
		this.resolveGeneration += 1
		// Abort work that could otherwise finish after teardown.
		this._openAbort?.abort()
		this._contentAbort?.abort()
		this.teardownSync()
	},
	beforeUnmount() {
		this.resolveGeneration += 1
		this._openAbort?.abort()
		this._contentAbort?.abort()
		this.teardownSync()
	},
	render(createElement) {
		if (this.loadError) {
			const cardChildren = [
				createElement('div', { class: 'epnc-native-error-title' }, translate('Could not open pad')),
				createElement('div', { class: 'epnc-native-error-message' }, this.loadError),
			]
			if (this.maybeStaleFileId) {
				cardChildren.push(
					createElement('div', { class: 'epnc-native-error-message' },
						translate('This file may have been moved or replaced since the list was loaded. Reload the page and open it again.')),
				)
			}
			if (this.canRecover) {
				if (this.isCheckingOriginal) {
					// Wait before choosing the primary recovery action.
					cardChildren.push(
						createElement('div', { class: 'epnc-native-error-message' },
							translate('Checking for the original pad...')),
					)
				} else if (this.originalPad) {
					cardChildren.push(
						createElement('div', { class: 'epnc-native-error-message' },
							translate('This file looks like a copy of an existing .pad file in your account. Open the original to keep editing the linked pad, or create a new pad to fork the content stored in this file.')),
						createElement('a', {
							class: 'button primary epnc-native-error-action',
							attrs: { href: this.originalPad.viewerUrl },
						}, translate('Open the original .pad file')),
						createElement('button', {
							class: 'button epnc-native-error-action',
							attrs: { type: 'button', disabled: this.isRecovering },
							on: { click: () => { void this.recoverFromSnapshot() } },
						}, this.isRecovering ? translate('Creating new pad...') : translate('Create new pad from this file')),
					)
				} else {
					cardChildren.push(
						createElement('div', { class: 'epnc-native-error-message' },
							translate("We couldn't find a matching pad in this Nextcloud. You can create a new pad from the text stored in this file; from then on, opening this file will load the new pad.")),
						createElement('button', {
							class: 'button primary epnc-native-error-action',
							attrs: { type: 'button', disabled: this.isRecovering },
							on: { click: () => { void this.recoverFromSnapshot() } },
						}, this.isRecovering ? translate('Creating new pad...') : translate('Create new pad from this file')),
					)
				}
			}
			return createElement('div', { class: 'epnc-native-status epnc-native-status--error' }, [
				createElement('div', { class: 'epnc-native-error-card' }, cardChildren),
			])
		}
		if (this.contentMode === 'content') {
			return this.renderContentView(createElement, this.externalOpenUrl === ''
				? {}
				: {
					actions: [
						createElement('a', {
							class: 'button epnc-pad-doc__link',
							attrs: {
								href: this.externalOpenUrl,
								target: '_blank',
								rel: 'noopener noreferrer',
							},
						}, translate('Open original pad')),
					],
				})
		}
		if (this.isLoading || !this.iframeSrc) {
			return createElement('div', { class: 'epnc-native-status' }, 'Loading pad...')
		}

		return createElement('div', { class: 'epnc-native-shell' }, [
			// Nextcloud inspects direct iframe children, so keep this wrapper same-origin.
			createElement('iframe', {
				attrs: { srcdoc: buildPadFrameSrcdoc(this.iframeSrc), title: 'Etherpad' },
				// Etherpad provides its own loading state inside the nested iframe.
				on: { load: () => this.markLoaded(), error: () => this.markLoaded() },
				class: 'epnc-native-iframe',
			}),
		])
	},
}

export default component
