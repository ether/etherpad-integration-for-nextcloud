/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME, VIEWER_HANDLER_ID } from './lib/constants.js'
import { apiFindOriginalPad, apiRecoverFromSnapshot, apiResolvePadByPath } from './lib/api-client.js'
import { fetchJsonWithTimeout } from './lib/fetch-helpers.js'
import { ocGenerateUrl, ocRequestToken, translate } from './lib/oc-compat.js'
import { createPadSync } from './lib/pad-sync.js'
import { loadPadContent } from './lib/pad-content.js'
import { buildPadFrameSrcdoc } from './lib/pad-frame-srcdoc.js'
import { parsePadPathFromDavHref, parsePublicShareTokenFromLocation } from './lib/urls.js'

(function () {
	let attempts = 0

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
				// The id recovery may address. Normally the Viewer's own, but
				// resolved from the path when it supplies none.
				recoveryFileId: null,
				// The path recoveryFileId was resolved from, so invalidating
				// its cache entry names the same file the id does.
				recoveryPath: '',
				isRecovering: false,
				isCheckingOriginal: false,
				originalPad: null,
				externalOpenUrl: '',
				// Which read-only surface is showing, if any: '' for the
				// editor, 'readonly' for a share without write permission,
				// 'external' for a pad on a foreign server.
				contentMode: '',
				contentUrl: '',
				contentState: 'idle',
				contentError: '',
				content: { html: '', isEmpty: false },
				resolveGeneration: 0,
			}
		},
		computed: {
			sourcePath() {
				// The one place a trim is safe: `source` is a DAV href, and a
				// URL is not a name — padding inside one is percent-encoded,
				// so only transport noise around it can be removed here.
				const value = typeof this.source === 'string' ? this.source.trim() : ''
				if (!value) return ''
				return parsePadPathFromDavHref(value) || ''
			},
			filePath() {
				// Names are passed through, not cleaned up. This used to
				// collapse " .pad" to ".pad" and to trim both the name and the
				// directory, so `Notes .pad`, ` A.pad` and a folder called
				// `Folder ` each asked the server for a neighbour of the file
				// that was clicked. Nextcloud accepts all three: its validator
				// trims only to decide whether a name is empty or `.`/`..`,
				// and judges everything else on the name as given. What a name
				// may be is settled when the file is created, not while
				// opening one.
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
				const isPadPath = (value) => typeof value === 'string' && value.toLowerCase().endsWith('.pad')
				if (isPadPath(this.sourcePath)) return this.sourcePath

				const info = this.fileInfo && typeof this.fileInfo === 'object' ? this.fileInfo : null
				const infoPath = info && typeof info.path === 'string' ? info.path : ''
				if (isPadPath(infoPath)) return infoPath.startsWith('/') ? infoPath : ('/' + infoPath)

				const baseName = String(this.filename || this.basename || (info && (info.name || info.basename)) || '')
				if (!baseName) return ''

				const infoDir = info && typeof info.dirname === 'string' ? info.dirname : ''
				if (infoDir) {
					const combined = joinPath(infoDir, baseName)
					if (isPadPath(combined)) return combined
				}

				const params = new URLSearchParams(window.location.search || '')
				const urlDir = params.get('dir') || '/'
				const fromDir = joinPath(urlDir, baseName)
				if (isPadPath(fromDir)) return fromDir
				return '/' + baseName
			},
			/** What the open depends on, as one value — see the watcher. */
			openKey() {
				return `${this.resolvedFileId === null ? '' : this.resolvedFileId}::${this.filePath}`
			},
			resolvedFileId() {
				const candidates = [this.fileid, this.fileId, this.fileInfo && (this.fileInfo.fileid || this.fileInfo.fileId || this.fileInfo.id)]
				for (const candidate of candidates) {
					const numeric = Number(candidate)
					if (Number.isFinite(numeric) && numeric > 0) return numeric
				}
				// Only the props. The Files URL also carries an id, and this
				// used to read it when `openfile=true` — but that id belongs
				// to whatever the route was opened with, while `filePath`
				// follows the file the Viewer is showing. The two part
				// company as soon as the user steps to the next pad, and
				// since opening by id no longer falls back to the path,
				// the id would decide. A file the viewer cannot name an id
				// for is opened by path instead.
				return null
			},
		},
		watch: {
			// One key, so one resolve. Watching filePath and resolvedFileId
			// separately fired twice on every file swap — both change at
			// once — and the generation guard discards the loser's result
			// without cancelling its request. For a protected pad that
			// second request had already minted an Etherpad session and a
			// cookie that nothing would ever use.
			openKey: { immediate: true, handler() { void this.resolveOpenUrl() } },
		},
		methods: {
			/**
			 * The shared helper does the rest: Accept, merged headers, an
			 * error carrying `.status` and `.code`, and a request timeout —
			 * without which an unresponsive server left the viewer on
			 * "Loading pad..." with no error and no way out. This method is
			 * only the pad-specific part: the payload has to name a URL, or
			 * be a read-only snapshot.
			 */
			async fetchOpenPayload(url, init = {}) {
				const data = await fetchJsonWithTimeout(url, Object.assign({ method: 'GET' }, init))
				if (!data || (data.is_readonly_view !== true && (typeof data.url !== 'string' || data.url.trim() === ''))) {
					throw new Error('Pad open API did not return a valid URL.')
				}
				return data
			},
			isMissingFrontmatterError(error) {
				if (!(error instanceof Error)) return false
				return String(error.message || '').includes('Missing YAML frontmatter')
			},
			async initializeMissingFrontmatter() {
				const headers = {
					Accept: 'application/json',
					requesttoken: ocRequestToken(),
				}

				const buildInitError = (data, fallbackMessage, status = 0) => {
					const err = new Error((data && data.message) || fallbackMessage)
					// Same shape as fetchOpenPayload's errors: the status
					// explains the failure to the error card. Without it an
					// initialize that 404s — the file moved between the open
					// and the retry — is the dead-end card again.
					err.status = status
					// Forward a structured code so callers can branch on
					// `legacy_collision_no_access` without parsing the message.
					if (data && typeof data.code === 'string' && data.code !== '') {
						err.code = data.code
					}
					return err
				}

				const announceMigratedStatus = (data) => {
					if (data && data.status === 'migrated_from_legacy') {
						// Audit-visible on the backend; mirror it in the
						// browser console so dev tools makes the conversion
						// visible without surfacing a UI toast (the codebase
						// has no toast infra wired yet).
						console.info('Legacy Ownpad .pad migrated to managed format on first open.')
					}
				}

				if (this.resolvedFileId !== null) {
					const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize-by-id/' + encodeURIComponent(String(this.resolvedFileId)))
					const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers })
					const data = await response.json().catch(() => ({}))
					if (!response.ok) {
						throw buildInitError(data, 'Pad initialization failed.', response.status)
					}
					announceMigratedStatus(data)
					return data
				}

				if (!this.filePath) {
					throw new Error('Pad initialization failed: missing file path.')
				}

				const body = new URLSearchParams()
				body.set('file', this.filePath)
				const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize')
				const response = await fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: Object.assign({}, headers, {
						'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					}),
					body: body.toString(),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw buildInitError(data, 'Pad initialization failed.', response.status)
				}
				announceMigratedStatus(data)
				return data
			},
			/**
			 * The file id of the path currently open, or null. Only used to
			 * give recovery an address; a failure here just means no
			 * recovery action, never a different file.
			 *
			 * Asked without the cache: recovery writes, and a five-minute-old
			 * path-to-id answer can name a file that has since moved out of
			 * the way of another.
			 */
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
			// Lazily build the shared sync controller. Kept off `data()` on
			// purpose so it is not made reactive, and memoised on a plain
			// instance field so it survives the immediate openKey watcher
			// (which runs before created/mounted).
			padSync() {
				if (!this._padSync) {
					this._padSync = createPadSync({ requestToken: () => ocRequestToken() })
				}
				return this._padSync
			},
			// Final flush + full teardown on destroy. Guard on the existing
			// controller so we never spin one up just to tear it down (a viewer
			// destroyed before its first open). The lazy create stays in the
			// resolve path, which actually needs to sync.
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
				// Abort whatever the previous resolve still has in flight.
				// The generation guard only discards its *result*: the request
				// itself ran to completion, and for a protected pad that means
				// the server had already minted an Etherpad session and a
				// cookie nothing would ever use. openKey collapses the common
				// case to one resolve; this covers the rest — props arriving
				// in separate ticks, or a recovery re-resolving mid-flight.
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
				this._contentAbort?.abort()
				// Reset only an existing controller; don't construct one just to
				// stop/clear it (e.g. the initial immediate watcher with no pad).
				// The success path below lazily creates it when there's a pad to
				// actually sync.
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

				// The path this resolve is about, captured once. filePath is a
				// computed and updates the moment the Viewer swaps props —
				// before the watcher has flushed and bumped the generation —
				// so re-reading it later can describe a different file than
				// the one this open and its error card are about.
				const openPath = this.filePath
				const publicToken = parsePublicShareTokenFromLocation()
				const byPublicUrl = (() => {
					if (!publicToken) return ''
					const url = new URL(ocGenerateUrl('/apps/' + APP_ID + '/api/v1/public/open/' + encodeURIComponent(publicToken)), window.location.origin)
					url.searchParams.set('file', openPath)
					return url.toString()
				})()
				const openPostHeaders = {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: ocRequestToken(),
				}

				try {
					const fetchOpenData = async () => {
							// Exactly one way in, chosen once. Opening by id
							// used to retry by path when it failed, which is
							// how a refused id ended up opening whatever the
							// path pointed at — see the cases pinned in
							// tests/js/viewer-main.test.js.
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

					let data
					try {
						data = await fetchOpenData()
						if (!isCurrent()) return
					} catch (error) {
						if (!this.isMissingFrontmatterError(error)) {
							throw error
						}
						// Deliberately *not* abortable. It creates a pad, writes a
						// binding row and rewrites the file; tearing the
						// connection down mid-write leaves the server to finish
						// (or not) with nobody left to read the outcome. A
						// superseded read costs a wasted request — this would
						// cost a half-applied change. recoverFromSnapshot, the
						// other write, is not abortable either.
						await this.initializeMissingFrontmatter()
						if (!isCurrent()) return
						data = await fetchOpenData()
						if (!isCurrent()) return
					}

					const syncUrl = (data && typeof data.sync_url === 'string') ? data.sync_url : ''

					const intervalSeconds = Number(data && data.sync_interval_seconds)
					const intervalMs = (Number.isFinite(intervalSeconds) && intervalSeconds > 0)
						? Math.max(5000, Math.min(3600000, intervalSeconds * 1000))
						: 120000

					this.padSync().configure({ syncUrl, intervalMs })
					this.padSync().installLifecycleHandlers()
					if (syncUrl) {
						this.padSync().start()
					}

					const contentUrl = (data && typeof data.content_url === 'string') ? data.content_url.trim() : ''

					if (data && data.is_readonly_view === true) {
						this.contentMode = 'readonly'
						this.contentUrl = contentUrl
						this.markLoaded()
						// Not awaited: the frame is drawn now and fills in
						// when the pad answers, so a slow pad server shows a
						// loading state instead of an empty viewer.
						void this.loadContent()
						return
					}

					if (data && data.is_external === true && typeof data.url === 'string' && data.url.trim() !== '') {
						this.externalOpenUrl = data.url.trim()
						this.contentMode = 'external'
						this.contentUrl = contentUrl
						this.markLoaded()
						void this.loadContent()
						return
					}

					this.iframeSrc = data.url
					this.markLoaded()
				} catch (error) {
					if (!isCurrent()) return
					this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
					// The file id named a node the server could not hand over,
					// and it cannot say whether that is because the file moved
					// or because the id is not this user's. Since the open no
					// longer answers that by trying the path, say what the one
					// remedy is rather than leaving a dead end.
					// `!byPublicUrl` rather than asking the location again: it
					// is the branch that actually ran, and an SPA route change
					// mid-flight would make a fresh lookup disagree with it.
					this.maybeStaleFileId = this.resolvedFileId !== null
						&& !byPublicUrl
						&& Boolean(error) && error.status === 404 && !error.code
					// Recovery needs an id it may address. The Viewer's own is
					// preferred; when it supplies none — the case the Files
					// URL's id used to paper over, unsafely, because that id
					// need not belong to the file on screen — the id is asked
					// for by the path that was just opened. Same file by
					// construction, and the server resolves it inside the
					// user's own tree.
					let recoveryFileId = this.resolvedFileId
					this.recoveryPath = openPath
					if (recoveryFileId === null && !byPublicUrl && error && error.code === 'missing_binding') {
						recoveryFileId = await this.resolveRecoveryFileId(openPath)
						// Assigned only after the guard: a slower lookup from a
						// superseded resolve would otherwise overwrite the live
						// card's address, and the recovery button would then
						// create a pad for the file the user has left.
						if (!isCurrent()) return
					}
					this.recoveryFileId = recoveryFileId
					// Public-share visitors don't get a recovery action — only
					// the share owner.
					this.canRecover = Boolean(error && error.code === 'missing_binding')
						&& recoveryFileId !== null
						&& !byPublicUrl
					if (this.canRecover) {
						// Optional: check if this looks like a copy of a .pad we
						// can already address; if so we'll offer 'Open the
						// original' as the primary action. A miss is silent — no
						// UI element rendered, no info leaked.
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
					// Silent: the recovery button stays available, we just
					// don't surface the "Open the original" affordance.
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
			/**
			 * Loads what the pad says now, and again on every retry.
			 *
			 * The endpoint re-checks access on each call, so a share
			 * withdrawn while the tab sat open stops answering rather than
			 * keeping the reader supplied.
			 */
			async loadContent() {
				const generation = this.resolveGeneration
				const isCurrent = () => generation === this.resolveGeneration

				if (this.contentUrl === '') {
					this.contentState = 'error'
					this.contentError = translate('The server did not say where to load this pad from.')
					return
				}

				this._contentAbort?.abort()
				const abort = new AbortController()
				this._contentAbort = abort
				this.contentState = 'loading'
				this.contentError = ''

				try {
					const content = await loadPadContent(this.contentUrl, { signal: abort.signal })
					if (!isCurrent()) return
					this.content = content
					this.contentState = 'ready'
				} catch (error) {
					if (!isCurrent() || (error && error.name === 'AbortError')) return
					this.contentState = 'error'
					this.contentError = error instanceof Error ? error.message : translate('Could not load the pad content.')
				}
			},
			renderContentView(createElement, options) {
				const actions = Array.isArray(options.actions) ? options.actions : []
				const body = this.renderContentBody(createElement)

				return createElement('div', { class: 'epnc-native-snapshot' }, [
					createElement('div', { class: 'epnc-native-snapshot__inner' }, [
						createElement('div', { class: 'epnc-native-snapshot__header' }, [
							createElement('div', { class: 'epnc-native-snapshot__heading' }, [
								createElement('div', { class: 'epnc-native-snapshot__title' }, options.title),
								createElement('div', { class: 'epnc-native-snapshot__message' }, options.message),
							]),
							actions.length > 0
								? createElement('div', { class: 'epnc-native-snapshot__actions' }, actions)
								: null,
						]),
						body,
					]),
				])
			},
			renderContentBody(createElement) {
				if (this.contentState === 'error') {
					return createElement('div', { class: 'epnc-native-snapshot__text epnc-native-snapshot__status' }, [
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
					return createElement('div', { class: 'epnc-native-snapshot__text epnc-native-snapshot__status' }, translate('Loading pad content...'))
				}
				// An empty pad loaded fine. Saying so is the whole point:
				// silence here reads as a failure that was never reported.
				if (this.content.isEmpty) {
					return createElement('div', { class: 'epnc-native-snapshot__text epnc-native-snapshot__status' }, translate('This pad is still empty.'))
				}
				return createElement('div', {
					class: 'epnc-native-snapshot__text epnc-native-snapshot__text--html',
					domProps: { innerHTML: this.content.html },
				})
			},
		},
		beforeDestroy() {
			this.resolveGeneration += 1
			// Closing the viewer mid-open is the commonest way to supersede a
			// request; bumping the generation only drops the answer.
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
						// Don't render any action button while the lookup is in
						// flight: a slow connection could otherwise let the user
						// click 'Create new pad' before we know that opening the
						// original is the better default.
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
			if (this.contentMode === 'external') {
				return this.renderContentView(createElement, {
					title: translate('Pad from another server'),
					message: translate('Loaded when you opened this pad.'),
					actions: [
						createElement('a', {
							class: 'button primary',
							attrs: {
								href: this.externalOpenUrl,
								target: '_blank',
								rel: 'noopener noreferrer',
							},
						}, translate('Open original pad')),
					],
				})
			}
			if (this.contentMode === 'readonly') {
				return this.renderContentView(createElement, {
					title: translate('Read-only view'),
					message: translate('Loaded when you opened this pad.'),
				})
			}
			if (this.isLoading || !this.iframeSrc) {
				return createElement('div', { class: 'epnc-native-status' }, 'Loading pad...')
			}

			return createElement('div', { class: 'epnc-native-shell' }, [
				// Nextcloud Viewer tries to inspect/focus direct iframe children during
				// teardown. Keep the direct iframe same-origin via srcdoc, and put the
				// cross-origin Etherpad frame one level deeper.
				createElement('iframe', {
					attrs: { srcdoc: buildPadFrameSrcdoc(this.iframeSrc), title: 'Etherpad' },
					// This fires when the srcdoc wrapper is ready. Etherpad then continues
					// loading in the inner iframe and shows its own loading UI.
					on: { load: () => this.markLoaded(), error: () => this.markLoaded() },
					class: 'epnc-native-iframe',
				}),
			])
		},
	}

	const tryRegister = () => {
		attempts += 1
		if (!(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.registerHandler === 'function')) {
			if (attempts < 20) window.setTimeout(tryRegister, 500)
			return
		}
		if (Array.isArray(window.OCA.Viewer.availableHandlers)
			&& window.OCA.Viewer.availableHandlers.some((handler) => handler && handler.id === VIEWER_HANDLER_ID)) {
			return
		}
		window.OCA.Viewer.registerHandler({ id: VIEWER_HANDLER_ID, mimes: [MIME], component })
	}

	tryRegister()
})()
