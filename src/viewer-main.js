/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME, VIEWER_HANDLER_ID } from './lib/constants.js'
import { apiFindOriginalPad, apiRecoverFromSnapshot } from './lib/api-client.js'
import { ocGenerateUrl, ocRequestToken, translate } from './lib/oc-compat.js'
import { createPadSync } from './lib/pad-sync.js'
import { sanitizeSnapshotHtml } from './lib/sanitize-html.js'
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
				isRecovering: false,
				isCheckingOriginal: false,
				originalPad: null,
				externalOpenUrl: '',
				externalOpenMessage: '',
				snapshotMode: '',
				snapshot: { text: '', html: '' },
				resolveGeneration: 0,
			}
		},
		computed: {
			sourcePath() {
				const value = typeof this.source === 'string' ? this.source.trim() : ''
				if (!value) return ''
				return parsePadPathFromDavHref(value) || ''
			},
			filePath() {
				const normalizeName = (value) => String(value || '').trim().replace(/\s+\.pad$/i, '.pad')
				const normalizeDir = (value) => {
					const dir = String(value || '').trim()
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
				const infoPath = info && typeof info.path === 'string' ? normalizeName(info.path) : ''
				if (isPadPath(infoPath)) return infoPath.startsWith('/') ? infoPath : ('/' + infoPath)

				const baseName = normalizeName(this.filename || this.basename || (info && (info.name || info.basename)) || '')
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
			resolvedFileId() {
				const candidates = [this.fileid, this.fileId, this.fileInfo && (this.fileInfo.fileid || this.fileInfo.fileId || this.fileInfo.id)]
				for (const candidate of candidates) {
					const numeric = Number(candidate)
					if (Number.isFinite(numeric) && numeric > 0) return numeric
				}
				const params = new URLSearchParams(window.location.search || '')
				if (params.get('openfile') !== 'true') return null
				const match = (window.location.pathname || '').match(/\/apps\/files\/files\/(\d+)\/?$/)
				if (!match) return null
				const fallbackId = Number(match[1])
				return Number.isFinite(fallbackId) && fallbackId > 0 ? fallbackId : null
			},
		},
		watch: {
			filePath: { immediate: true, handler() { void this.resolveOpenUrl() } },
			resolvedFileId() { void this.resolveOpenUrl() },
		},
		methods: {
			async fetchOpenPayload(url, init = {}) {
				const headers = Object.assign({ Accept: 'application/json' }, init.headers || {})
				const response = await fetch(url, Object.assign({
					method: 'GET',
					credentials: 'same-origin',
					headers,
				}, init))
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					const error = new Error((data && data.message) || 'Pad open failed.')
					if (data && typeof data.code === 'string') {
						error.code = data.code
					}
					throw error
				}
				if (!data || (data.is_readonly_snapshot !== true && (typeof data.url !== 'string' || data.url.trim() === ''))) {
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

				const buildInitError = (data, fallbackMessage) => {
					const err = new Error((data && data.message) || fallbackMessage)
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
						throw buildInitError(data, 'Pad initialization failed.')
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
					throw buildInitError(data, 'Pad initialization failed.')
				}
				announceMigratedStatus(data)
				return data
			},
			markLoaded() {
				this.$emit('update:loaded', true)
			},
			// Lazily build the shared sync controller. Kept off `data()` on
			// purpose so it is not made reactive, and memoised on a plain
			// instance field so it survives the immediate filePath watcher
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

				this.isLoading = true
				this.loadError = ''
				this.canRecover = false
				this.isCheckingOriginal = false
				this.originalPad = null
				this.iframeSrc = ''
				this.externalOpenUrl = ''
				this.externalOpenMessage = ''
				this.snapshotMode = ''
				this.snapshot = { text: '', html: '' }
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

				const publicToken = parsePublicShareTokenFromLocation()
				const byPathUrl = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open')
				const byPublicUrl = (() => {
					if (!publicToken) return ''
					const url = new URL(ocGenerateUrl('/apps/' + APP_ID + '/api/v1/public/open/' + encodeURIComponent(publicToken)), window.location.origin)
					url.searchParams.set('file', this.filePath)
					return url.toString()
				})()
				const byIdUrl = this.resolvedFileId !== null
					? ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open-by-id')
					: ''
				const byPathBody = new URLSearchParams()
				byPathBody.set('file', this.filePath)
				const byIdBody = new URLSearchParams()
				if (this.resolvedFileId !== null) {
					byIdBody.set('fileId', String(this.resolvedFileId))
				}
				const openPostHeaders = {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: ocRequestToken(),
				}

				try {
					const fetchOpenData = async () => {
							let data = null
							if (byPublicUrl) {
								data = await this.fetchOpenPayload(byPublicUrl)
							} else if (byIdUrl) {
								try {
									data = await this.fetchOpenPayload(byIdUrl, {
										method: 'POST',
										headers: openPostHeaders,
										body: byIdBody.toString(),
									})
								} catch {
									// Fallback for moved/renamed files where stale fileId can fail.
								}
							}
							if (!data) {
								data = await this.fetchOpenPayload(byPathUrl, {
									method: 'POST',
									headers: openPostHeaders,
									body: byPathBody.toString(),
								})
							}
							return data
						}

					let data
					try {
						data = await fetchOpenData()
						if (!isCurrent()) return
					} catch (error) {
						if (!this.isMissingFrontmatterError(error)) {
							throw error
						}
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

					if (data && data.is_readonly_snapshot === true) {
						this.snapshotMode = 'readonly'
						this.snapshot = {
							text: (typeof data.snapshot_text === 'string') ? data.snapshot_text : '',
							html: (typeof data.snapshot_html === 'string') ? data.snapshot_html : '',
						}
						this.markLoaded()
						return
					}

					if (data && data.is_external === true && typeof data.url === 'string' && data.url.trim() !== '') {
						const targetUrl = data.url.trim()
						this.externalOpenUrl = targetUrl
						this.externalOpenMessage = translate('Read-only snapshot from the .pad file.')
						this.snapshotMode = 'external'
						this.snapshot = {
							text: (data && typeof data.snapshot_text === 'string') ? data.snapshot_text : '',
							html: (data && typeof data.snapshot_html === 'string') ? data.snapshot_html : '',
						}
						this.markLoaded()
						return
					}

					this.iframeSrc = data.url
					this.markLoaded()
				} catch (error) {
					if (!isCurrent()) return
					this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
					// Recovery is gated on having a fileId we can address. Public-share
					// visitors don't get a recovery action — only the share owner.
					this.canRecover = Boolean(error && error.code === 'missing_binding')
						&& this.resolvedFileId !== null
						&& !parsePublicShareTokenFromLocation()
					if (this.canRecover) {
						// Optional: check if this looks like a copy of a .pad we
						// can already address; if so we'll offer 'Open the
						// original' as the primary action. A miss is silent — no
						// UI element rendered, no info leaked.
						this.fetchOriginalPadHint(generation, isCurrent)
					}
					this.markLoaded()
				} finally {
					if (!isCurrent()) return
					this.isLoading = false
				}
			},
			async fetchOriginalPadHint(generation, isCurrent) {
				if (this.resolvedFileId === null) {
					return
				}
				this.isCheckingOriginal = true
				try {
					const hint = await apiFindOriginalPad(this.resolvedFileId)
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
				if (!this.canRecover || this.isRecovering || this.resolvedFileId === null) {
					return
				}
				this.isRecovering = true
				try {
					await apiRecoverFromSnapshot(this.resolvedFileId)
					this.loadError = ''
					this.canRecover = false
					await this.resolveOpenUrl()
				} catch (error) {
					this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
				} finally {
					this.isRecovering = false
				}
			},
			renderSnapshotView(createElement, options) {
				const html = sanitizeSnapshotHtml(options.html)
				const text = String(options.text || '')
				const actions = Array.isArray(options.actions) ? options.actions : []

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
						html.trim() !== ''
							? createElement('div', {
								class: 'epnc-native-snapshot__text epnc-native-snapshot__text--html',
								domProps: { innerHTML: html },
							})
							: createElement('pre', { class: 'epnc-native-snapshot__text' }, text.trim() !== ''
								? text
								: options.emptyMessage),
					]),
				])
			},
		},
		beforeDestroy() {
			this.resolveGeneration += 1
			this.teardownSync()
		},
		beforeUnmount() {
			this.resolveGeneration += 1
			this.teardownSync()
		},
		render(createElement) {
			if (this.loadError) {
				const cardChildren = [
					createElement('div', { class: 'epnc-native-error-title' }, translate('Could not open pad')),
					createElement('div', { class: 'epnc-native-error-message' }, this.loadError),
				]
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
			if (this.snapshotMode === 'external') {
				return this.renderSnapshotView(createElement, {
					title: translate('Pad from another server'),
					message: this.externalOpenMessage,
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
					html: this.snapshot.html,
					text: this.snapshot.text,
					emptyMessage: translate('No synced snapshot is stored in this .pad file yet.'),
				})
			}
			if (this.snapshotMode === 'readonly') {
				return this.renderSnapshotView(createElement, {
					title: translate('Read-only snapshot'),
					message: translate('Read-only snapshot from the .pad file.'),
					html: this.snapshot.html,
					text: this.snapshot.text,
					emptyMessage: translate('No synced snapshot is stored in this .pad file yet.'),
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
