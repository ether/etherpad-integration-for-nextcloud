/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME } from './lib/constants.js'
import {
	ocGenerateUrl,
	ocImagePath,
	ocPermissionRead,
	ocRequestToken,
} from './lib/oc-compat.js'
import { hasNativeViewer, isFilesAppRoute } from './lib/nextcloud-runtime.js'
import { parsePadPathFromDavHref, parsePublicShareTokenFromLocation } from './lib/urls.js'
import { openCreatedPadInViewer } from './files/created-pad-opener.js'
import { createPublicPadMenuRegistrar } from './files/public-pad-menu.js'
import {
	openExternalPublicPadDialog,
	openInternalPublicPadDialog,
	openPublicPadModeDialog,
} from './files/pad-create-dialogs.js'
import { isSingleFilePublicShare, schedulePublicSingleShareUiStateRefresh } from './files/public-single-share-ui.js'

(function () {
	const OPEN_ACTION = 'etherpad_nextcloud_open'
	const RESOLVE_CACHE = new Map()
	let booted = false
	let sidebarSyncObserver = null
	let lastOpenKey = null
	let lastOpenAt = 0
	let lastRouteCheckKey = ''
	let sidebarSyncRefreshTimer = null
	let sidebarSyncStatusPollTimer = null
	let sidebarSyncStatusPollFileId = null
	let sidebarSyncRefreshToken = 0
	const SIDEBAR_PANEL_ATTR = 'data-epnc-sidebar-sync-panel'
	const SIDEBAR_PANEL_MOUNT_ATTR = 'data-epnc-sidebar-sync-mount'
	const SIDEBAR_SYNC_STATUS_POLL_BASE_MS = 8000
	const SIDEBAR_SYNC_STATUS_POLL_MAX_MS = 60000
	const SIDEBAR_SYNC_STATUS_POLL_ERROR_MS = 15000
	const SIDEBAR_SYNC_STATUS_POLL_STEP_MS = 8000

	const normalizeFilePath = (dir, filename) => {
		const cleanDir = !dir || dir === '/' ? '' : String(dir)
		const cleanName = String(filename || '').replace(/^\/+/, '').replace(/\s+\.pad$/i, '.pad')
		if (cleanDir === '') {
			return '/' + cleanName
		}
		return cleanDir + '/' + cleanName
	}

	const viewerUrlForPath = (path) => ocGenerateUrl('/apps/' + APP_ID + '/?file=' + encodeURIComponent(path))
	const filesUrlForFileId = (fileId, path) => {
		const base = ocGenerateUrl('/apps/files/files/' + encodeURIComponent(String(fileId)))
		const params = new URLSearchParams()
		params.set('dir', resolveOpenDir(path))
		params.set('editing', 'false')
		params.set('openfile', 'true')
		return base + '?' + params.toString()
	}
	const parseFileIdFromFilesHref = (href) => {
		if (!href || typeof href !== 'string') {
			return null
		}
		let url
		try {
			url = new URL(href, window.location.origin)
		} catch (error) {
			return null
		}
		const pathValue = url.pathname || ''
		const match = pathValue.match(/\/apps\/files\/files\/(\d+)\/?$/)
		if (!match) {
			const shareMatch = pathValue.match(/\/f\/(\d+)\/?$/)
			if (!shareMatch) {
				const byQuery = Number(url.searchParams.get('fileid') || '')
				return Number.isFinite(byQuery) && byQuery > 0 ? byQuery : null
			}
			const shareId = parseInt(shareMatch[1], 10)
			return Number.isFinite(shareId) && shareId > 0 ? shareId : null
		}
		const id = parseInt(match[1], 10)
		return Number.isFinite(id) && id > 0 ? id : null
	}

	const parseFileIdFromCurrentLocation = () => {
		const match = (window.location.pathname || '').match(/\/apps\/files\/files\/(\d+)\/?$/)
		if (!match) {
			return null
		}
		const id = parseInt(match[1], 10)
		return Number.isFinite(id) && id > 0 ? id : null
	}

	const viewerUrlForPublicShare = (token, path) => {
		const base = ocGenerateUrl('/apps/' + APP_ID + '/public/' + encodeURIComponent(token))
		if (!path) {
			return base
		}
		return base + '?file=' + encodeURIComponent(path)
	}

	const parsePublicSharePadFromHref = (href) => {
		if (!href || typeof href !== 'string') {
			return null
		}
		let url
		try {
			url = new URL(href, window.location.origin)
		} catch (error) {
			return null
		}
		const pathMatch = (url.pathname || '').match(/(?:\/index\.php)?\/s\/([^/]+)\/download\/?$/)
		if (!pathMatch) {
			return null
		}
		const files = (url.searchParams.get('files') || '').trim()
		if (!isPadName(files)) {
			return null
		}
		const dir = (url.searchParams.get('path') || '/').trim() || '/'
		return {
			token: pathMatch[1],
			path: normalizeFilePath(dir, files),
		}
	}

	const isPadName = (name) => typeof name === 'string' && name.toLowerCase().endsWith('.pad')

	const apiResolvePadByFileId = async (fileId) => {
		const cacheKey = String(fileId)
		if (RESOLVE_CACHE.has(cacheKey)) {
			return RESOLVE_CACHE.get(cacheKey)
		}
		const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/resolve') + '?fileId=' + encodeURIComponent(cacheKey)
		const request = fetch(url, {
			method: 'GET',
			headers: { 'Accept': 'application/json' },
			credentials: 'same-origin',
		})
			.then(async (response) => {
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error((data && data.message) || 'Pad resolve failed.')
				}
				return data
			})
			.catch((error) => {
				RESOLVE_CACHE.delete(cacheKey)
				throw error
			})
		RESOLVE_CACHE.set(cacheKey, request)
		return request
	}

	const apiResolvePadByPath = async (path) => {
		const cacheKey = 'path:' + String(path)
		if (RESOLVE_CACHE.has(cacheKey)) {
			return RESOLVE_CACHE.get(cacheKey)
		}
		const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/resolve') + '?file=' + encodeURIComponent(path)
		const request = fetch(url, {
			method: 'GET',
			headers: { 'Accept': 'application/json' },
			credentials: 'same-origin',
		})
			.then(async (response) => {
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error((data && data.message) || 'Pad resolve by path failed.')
				}
				return data
			})
			.catch((error) => {
				RESOLVE_CACHE.delete(cacheKey)
				throw error
			})
		RESOLVE_CACHE.set(cacheKey, request)
		return request
	}

	const getDirFromPath = (path) => {
		if (!path || typeof path !== 'string') {
			return '/'
		}
		const idx = path.lastIndexOf('/')
		if (idx <= 0) {
			return '/'
		}
		return path.slice(0, idx) || '/'
	}

	const resolveOpenDir = (path) => {
		const dirFromPath = getDirFromPath(path)
		if (dirFromPath !== '/') {
			return dirFromPath
		}
		// When a file path has no directory context (or is already root), keep the
		// currently active Files dir in URL so close/back navigation returns to the
		// same folder instead of jumping to an unrelated default view.
		const currentDir = getCurrentDir()
		return currentDir === '' ? '/' : currentDir
	}

	const getCurrentDir = () => {
		const params = new URLSearchParams(window.location.search || '')
		const dir = (params.get('dir') || '/').trim()
		return dir === '' ? '/' : dir
	}

	const isDarkMode = () => {
		const root = document.documentElement
		const body = document.body
		const classes = (root?.className || '') + ' ' + (body?.className || '')
		if (/theme[-_]?dark|dark-theme|theme--dark/i.test(classes)) {
			return true
		}
		const media = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)')
		return Boolean(media && media.matches)
	}

	const isElementVisible = (element) => {
		if (!(element instanceof HTMLElement)) {
			return false
		}
		const style = window.getComputedStyle(element)
		if (style.display === 'none' || style.visibility === 'hidden') {
			return false
		}
		if (style.position === 'fixed') {
			return true
		}
		return element.offsetParent !== null
	}

	const parseFileIdFromRoute = () => {
		const fromPath = parseFileIdFromCurrentLocation()
		if (fromPath !== null) {
			return fromPath
		}
		const params = new URLSearchParams(window.location.search || '')
		const byQuery = Number(params.get('fileid') || '')
		return Number.isFinite(byQuery) && byQuery > 0 ? byQuery : null
	}

	const parseNumericFileId = (value) => {
		const id = Number(value)
		return Number.isFinite(id) && id > 0 ? id : null
	}

	const readFileIdCandidate = (source) => {
		if (!source) {
			return null
		}
		const directCandidates = [
			source.fileId,
			source.fileid,
			source.id,
			source.nodeId,
			source.nodeid,
			source.file_id,
		]
		for (const candidate of directCandidates) {
			const parsed = parseNumericFileId(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		if (typeof source.get === 'function') {
			for (const key of ['fileid', 'fileId', 'id']) {
				try {
					const parsed = parseNumericFileId(source.get(key))
					if (parsed !== null) {
						return parsed
					}
				} catch (error) {
					// Ignore model getter errors and keep probing other shapes.
				}
			}
		}
		return null
	}

	const parseFileIdFromElement = (element) => {
		if (!(element instanceof Element)) {
			return null
		}
		const selfCandidates = [
			element.getAttribute('data-fileid'),
			element.getAttribute('data-file-id'),
			element.getAttribute('data-id'),
			element.getAttribute('data-node-id'),
		]
		for (const candidate of selfCandidates) {
			const parsed = parseNumericFileId(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		const hrefNode = element.matches('a[href]') ? element : element.querySelector('a[href]')
		if (hrefNode instanceof HTMLAnchorElement) {
			return parseFileIdFromFilesHref(hrefNode.getAttribute('href'))
		}
		return null
	}

	const extractFileIdFromActionContext = (context) => {
		if (!context || typeof context !== 'object') {
			return null
		}
		const sources = [
			context,
			context.fileInfoModel,
			context.fileInfo,
			context.model,
			context.node,
			context.file,
			context.attributes,
		]
		for (const source of sources) {
			const parsed = readFileIdCandidate(source)
			if (parsed !== null) {
				return parsed
			}
		}
		const elementCandidates = [
			context.fileElement,
			context.el,
			context.element,
			context.$file && context.$file[0],
			context.$el && context.$el[0],
			context.target,
			context.currentTarget,
		]
		for (const candidate of elementCandidates) {
			const parsed = parseFileIdFromElement(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		return null
	}

	const findSidebarRoot = () => {
		const selectors = [
			'[data-cy-files-sidebar]',
			'#app-sidebar-vue .app-sidebar',
			'#app-sidebar-vue',
			'.app-sidebar',
		]
		for (const selector of selectors) {
			const node = document.querySelector(selector)
			if (node instanceof HTMLElement) {
				return node
			}
		}
		return null
	}

	const findSidebarPanelMount = (sidebarRoot) => {
		if (!(sidebarRoot instanceof HTMLElement)) {
			return null
		}
		let mount = sidebarRoot.querySelector('[' + SIDEBAR_PANEL_MOUNT_ATTR + '="1"]')
		if (!(mount instanceof HTMLElement)) {
			mount = document.createElement('div')
			mount.className = 'epnc-sidebar-sync-mount'
			mount.setAttribute(SIDEBAR_PANEL_MOUNT_ATTR, '1')
		}
		const tabs = sidebarRoot.querySelector('[data-cy-files-sidebar-tabs], .app-sidebar-tabs')
		if (tabs && tabs.parentNode instanceof HTMLElement) {
			const parent = tabs.parentNode
			if (mount.parentNode !== parent || mount.nextSibling !== tabs) {
				parent.insertBefore(mount, tabs)
			}
			return mount
		}
		if (mount.parentNode !== sidebarRoot) {
			sidebarRoot.insertBefore(mount, sidebarRoot.firstChild)
		}
		return mount
	}

	const resolveSidebarFileId = (sidebarRoot) => {
		const routeFileId = parseFileIdFromRoute()
		if (routeFileId !== null) {
			return routeFileId
		}
		if (!(sidebarRoot instanceof HTMLElement)) {
			return null
		}

		const attrSelectors = ['[data-fileid]', '[data-file-id]', '[data-node-id]', '[data-id]']
		for (const selector of attrSelectors) {
			const node = sidebarRoot.querySelector(selector)
			if (!(node instanceof HTMLElement)) {
				continue
			}
			const candidates = [
				node.getAttribute('data-fileid'),
				node.getAttribute('data-file-id'),
				node.getAttribute('data-node-id'),
				node.getAttribute('data-id'),
				node.dataset && (node.dataset.fileid || node.dataset.fileId),
			]
			for (const candidate of candidates) {
				const parsed = parseNumericFileId(candidate)
				if (parsed !== null) {
					return parsed
				}
			}
		}

		const hrefNodes = sidebarRoot.querySelectorAll('a[href]')
		for (const link of hrefNodes) {
			if (!(link instanceof HTMLAnchorElement)) {
				continue
			}
			const byHref = parseFileIdFromFilesHref(link.getAttribute('href'))
			if (byHref !== null) {
				return byHref
			}
		}

		return null
	}

	const syncIconNameForState = (state) => {
		if (state === 'synced') {
			return 'etherpad-icon-color'
		}
		return isDarkMode() ? 'etherpad-icon-white' : 'etherpad-icon-black'
	}

	const syncLabelForState = (state) => {
		if (state === 'synced') {
			return t(APP_ID, 'Synchronized')
		}
		if (state === 'syncing') {
			return t(APP_ID, 'Saving...')
		}
		if (state === 'error') {
			return t(APP_ID, 'Sync failed')
		}
		if (state === 'unavailable') {
			return t(APP_ID, 'Sync status unavailable')
		}
		if (state === 'loading') {
			return t(APP_ID, 'Checking sync status...')
		}
		return t(APP_ID, 'Sync pending')
	}

	const removeSidebarSyncPanel = () => {
		const panel = document.querySelector('[' + SIDEBAR_PANEL_ATTR + '="1"]')
		if (panel && panel.parentNode) {
			panel.parentNode.removeChild(panel)
		}
		const mount = document.querySelector('[' + SIDEBAR_PANEL_MOUNT_ATTR + '="1"]')
		if (mount && mount.parentNode) {
			mount.parentNode.removeChild(mount)
		}
		clearSidebarSyncStatusPoll()
	}

	const setSidebarSyncPanelPublicOpenUrl = (panel, publicOpenUrl) => {
		if (!(panel instanceof HTMLElement)) {
			return
		}
		const normalizedUrl = String(publicOpenUrl || '').trim()
		panel.setAttribute('data-public-open-url', normalizedUrl)
		const button = panel.querySelector('[data-epnc-sidebar-open-public-button]')
		if (!(button instanceof HTMLButtonElement)) {
			return
		}
		const hasUrl = normalizedUrl !== ''
		button.style.display = hasUrl ? 'inline-flex' : 'none'
		button.disabled = !hasUrl
	}

	const setSidebarSyncPanelState = (panel, state, fileId, syncInFlight, syncEnabled = true) => {
		if (!(panel instanceof HTMLElement)) {
			return
		}
		const icon = panel.querySelector('[data-epnc-sidebar-sync-icon]')
		const statusLabel = panel.querySelector('[data-epnc-sidebar-sync-label]')
		const button = panel.querySelector('[data-epnc-sidebar-sync-button]')

		panel.setAttribute('data-file-id', String(fileId))
		panel.setAttribute('data-sync-state', state)
		panel.setAttribute('data-sync-in-flight', syncInFlight ? '1' : '0')
		if (statusLabel instanceof HTMLElement) {
			statusLabel.textContent = syncLabelForState(state)
		}
		if (icon instanceof HTMLElement) {
			const iconName = syncIconNameForState(state)
			const iconPath = ocImagePath(APP_ID, iconName)
			if (iconPath !== '') {
				icon.style.backgroundImage = `url(${iconPath})`
			}
		}
		if (button instanceof HTMLButtonElement) {
			button.disabled = syncInFlight || !syncEnabled || fileId <= 0
			button.setAttribute('aria-busy', syncInFlight ? 'true' : 'false')
		}
	}

	const getOrCreateSidebarSyncPanel = (fileId, mountTarget) => {
		if (!(mountTarget instanceof HTMLElement)) {
			return null
		}
		let panel = mountTarget.querySelector('[' + SIDEBAR_PANEL_ATTR + '="1"]')
		if (!(panel instanceof HTMLElement)) {
			panel = document.createElement('section')
			panel.className = 'epnc-sidebar-sync-panel'
			panel.setAttribute(SIDEBAR_PANEL_ATTR, '1')
			panel.setAttribute('data-file-id', String(fileId))

			const header = document.createElement('div')
			header.className = 'epnc-sidebar-sync-panel__header'

			const icon = document.createElement('span')
			icon.className = 'epnc-sidebar-sync-panel__icon'
			icon.setAttribute('data-epnc-sidebar-sync-icon', '1')
			icon.setAttribute('aria-hidden', 'true')

			const statusLabel = document.createElement('span')
			statusLabel.className = 'epnc-sidebar-sync-panel__status'
			statusLabel.setAttribute('data-epnc-sidebar-sync-label', '1')

			header.appendChild(icon)
			header.appendChild(statusLabel)

			const button = document.createElement('button')
			button.type = 'button'
			button.className = 'button-vue button-vue--size-normal button-vue--vue-secondary epnc-sidebar-sync-panel__button'
			button.setAttribute('data-epnc-sidebar-sync-button', '1')
			button.textContent = t(APP_ID, 'Pad in Datei speichern')
			button.addEventListener('click', async (event) => {
				event.preventDefault()
				event.stopPropagation()
				const panelFileId = Number(panel.getAttribute('data-file-id') || '')
				if (!Number.isFinite(panelFileId) || panelFileId <= 0) {
					return
				}
					setSidebarSyncPanelState(panel, 'syncing', panelFileId, true, true)
					try {
						await apiSyncByFileId(panelFileId, true)
						const syncStatus = await apiSyncStatusByFileId(panelFileId)
						const status = normalizeSyncStatus(syncStatus && syncStatus.status)
						setSidebarSyncPanelState(panel, status, panelFileId, false, true)
					} catch (error) {
						setSidebarSyncPanelState(panel, 'error', panelFileId, false, true)
					}
				})

			const openPublicButton = document.createElement('button')
			openPublicButton.type = 'button'
			openPublicButton.className = 'button-vue button-vue--size-normal button-vue--vue-tertiary epnc-sidebar-sync-panel__button epnc-sidebar-sync-panel__button--public-open'
			openPublicButton.setAttribute('data-epnc-sidebar-open-public-button', '1')
			openPublicButton.textContent = t(APP_ID, 'Pad in neuem Tab öffnen')
			openPublicButton.style.display = 'none'
			openPublicButton.addEventListener('click', (event) => {
				event.preventDefault()
				event.stopPropagation()
				const targetUrl = String(panel.getAttribute('data-public-open-url') || '').trim()
				if (targetUrl === '') {
					return
				}
				window.open(targetUrl, '_blank', 'noopener,noreferrer')
			})

			panel.appendChild(header)
			panel.appendChild(button)
			panel.appendChild(openPublicButton)
		}
		if (panel.parentNode !== mountTarget) {
			mountTarget.appendChild(panel)
		}
		return panel
	}

	const normalizeSyncStatus = (status) => {
		if (status === 'synced') {
			return 'synced'
		}
		if (status === 'out_of_sync') {
			return 'pending'
		}
		if (status === 'unavailable') {
			return 'unavailable'
		}
		return 'error'
	}

	const apiSyncStatusByFileId = async (fileId) => {
		const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/sync-status/' + encodeURIComponent(String(fileId)))
		const response = await fetch(endpoint, {
			method: 'GET',
			headers: {
				Accept: 'application/json',
			},
			credentials: 'same-origin',
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Sync status check failed.')
		}
		return data
	}

	const apiSyncByFileId = async (fileId, force) => {
		let endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/sync/' + encodeURIComponent(String(fileId)))
		if (force) {
			endpoint += '?force=1'
		}
		const response = await fetch(endpoint, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				requesttoken: ocRequestToken(),
			},
			credentials: 'same-origin',
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Pad sync failed.')
		}
		return data
	}

	const clearSidebarSyncStatusPoll = () => {
		if (sidebarSyncStatusPollTimer !== null) {
			window.clearTimeout(sidebarSyncStatusPollTimer)
			sidebarSyncStatusPollTimer = null
		}
		sidebarSyncStatusPollFileId = null
	}

	const computeNextSidebarSyncPollDelay = (status, currentDelayMs) => {
		if (status === 'synced') {
			return Math.min(SIDEBAR_SYNC_STATUS_POLL_MAX_MS, currentDelayMs + SIDEBAR_SYNC_STATUS_POLL_STEP_MS)
		}
		if (status === 'pending') {
			return SIDEBAR_SYNC_STATUS_POLL_BASE_MS
		}
		return SIDEBAR_SYNC_STATUS_POLL_ERROR_MS
	}

	const startSidebarSyncStatusPoll = (panel, fileId) => {
		clearSidebarSyncStatusPoll()
		if (!(panel instanceof HTMLElement) || !Number.isFinite(fileId) || fileId <= 0) {
			return
		}
		sidebarSyncStatusPollFileId = fileId
		let nextDelayMs = SIDEBAR_SYNC_STATUS_POLL_BASE_MS
		const scheduleNext = (delayMs) => {
			sidebarSyncStatusPollTimer = window.setTimeout(() => {
				void runPoll()
			}, Math.max(1000, delayMs))
		}
		const runPoll = async () => {
			if (!(panel instanceof HTMLElement) || !document.body.contains(panel)) {
				clearSidebarSyncStatusPoll()
				return
			}
			const sidebarRoot = findSidebarRoot()
			if (!(sidebarRoot instanceof HTMLElement) || !isElementVisible(sidebarRoot) || !isElementVisible(panel)) {
				clearSidebarSyncStatusPoll()
				return
			}
			const activeFileId = resolveSidebarFileId(sidebarRoot)
			if (activeFileId !== fileId) {
				clearSidebarSyncStatusPoll()
				return
			}
			if (document.visibilityState !== 'visible' || panel.getAttribute('data-sync-in-flight') === '1') {
				scheduleNext(nextDelayMs)
				return
			}
			try {
				const syncStatus = await apiSyncStatusByFileId(fileId)
				const status = normalizeSyncStatus(syncStatus && syncStatus.status)
				setSidebarSyncPanelState(panel, status, fileId, false, true)
				nextDelayMs = computeNextSidebarSyncPollDelay(status, nextDelayMs)
			} catch (error) {
				setSidebarSyncPanelState(panel, 'error', fileId, false, true)
				nextDelayMs = SIDEBAR_SYNC_STATUS_POLL_ERROR_MS
			}
			scheduleNext(nextDelayMs)
		}
		scheduleNext(nextDelayMs)
	}

	const clearSidebarSyncRefreshTimer = () => {
		if (sidebarSyncRefreshTimer !== null) {
			window.clearTimeout(sidebarSyncRefreshTimer)
			sidebarSyncRefreshTimer = null
		}
	}

	const refreshSidebarSyncPanel = async () => {
		const refreshToken = ++sidebarSyncRefreshToken
		if (!isFilesAppRoute() || parsePublicShareTokenFromLocation()) {
			removeSidebarSyncPanel()
			return
		}
		const sidebarRoot = findSidebarRoot()
		if (!(sidebarRoot instanceof HTMLElement)) {
			removeSidebarSyncPanel()
			return
		}
		const fileId = resolveSidebarFileId(sidebarRoot)
		if (!fileId) {
			removeSidebarSyncPanel()
			return
		}
		const mountTarget = findSidebarPanelMount(sidebarRoot)
		if (!(mountTarget instanceof HTMLElement)) {
			removeSidebarSyncPanel()
			return
		}

		let resolved
		try {
			resolved = await apiResolvePadByFileId(fileId)
		} catch (error) {
			removeSidebarSyncPanel()
			return
		}
		if (refreshToken !== sidebarSyncRefreshToken) {
			return
		}
		if (!resolved || resolved.is_pad !== true) {
			removeSidebarSyncPanel()
			return
		}

		const panel = getOrCreateSidebarSyncPanel(fileId, mountTarget)
		if (!(panel instanceof HTMLElement)) {
			removeSidebarSyncPanel()
			return
		}
		const publicOpenUrl = (resolved && typeof resolved.public_open_url === 'string')
			? resolved.public_open_url.trim()
			: ''
		setSidebarSyncPanelPublicOpenUrl(panel, publicOpenUrl)
		setSidebarSyncPanelState(panel, 'loading', fileId, false, true)

		try {
			const syncStatus = await apiSyncStatusByFileId(fileId)
			if (refreshToken !== sidebarSyncRefreshToken) {
				return
			}
			const status = normalizeSyncStatus(syncStatus && syncStatus.status)
			setSidebarSyncPanelState(panel, status, fileId, false, true)
			startSidebarSyncStatusPoll(panel, fileId)
		} catch (error) {
			if (refreshToken !== sidebarSyncRefreshToken) {
				return
			}
			setSidebarSyncPanelState(panel, 'error', fileId, false, true)
			startSidebarSyncStatusPoll(panel, fileId)
		}
	}

	const scheduleSidebarSyncPanelRefresh = (delayMs = 0) => {
		clearSidebarSyncRefreshTimer()
		sidebarSyncRefreshTimer = window.setTimeout(() => {
			sidebarSyncRefreshTimer = null
			void refreshSidebarSyncPanel()
		}, Math.max(0, delayMs))
	}

	const apiCreatePadFromUrl = async (filePath, padUrl) => {
		const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/from-url')
		const body = new URLSearchParams()
		body.set('file', filePath)
		body.set('padUrl', padUrl)

		const response = await fetch(endpoint, {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: ocRequestToken(),
			},
			credentials: 'same-origin',
			body: body.toString(),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Could not import public pad URL.')
		}
		return data
	}

	const apiCreatePublicPad = async (filePath) => {
		const endpoint = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads')
		const body = new URLSearchParams()
		body.set('file', filePath)
		body.set('accessMode', 'public')

		const response = await fetch(endpoint, {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: ocRequestToken(),
			},
			credentials: 'same-origin',
			body: body.toString(),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error((data && data.message) || 'Could not create public pad.')
		}
		return data
	}

	const createInternalPublicPad = async () => {
		const inputName = await openInternalPublicPadDialog()
		if (!inputName) {
			return
		}
		const name = inputName.trim()
		const normalizedName = name.toLowerCase().endsWith('.pad') ? name : (name + '.pad')
		const filePath = normalizeFilePath(getCurrentDir(), normalizedName)

		try {
			const created = await apiCreatePublicPad(filePath)
			const createdPath = (created && typeof created.file === 'string') ? created.file : filePath
			const createdFileId = created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null
			await openCreatedPadInViewer(
				{
					path: createdPath,
					fileId: createdFileId,
				},
				{
					fallbackOpen: openPadInNativeViewer,
					resolveOpenDir,
				}
			)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not create public pad.')
			window.alert(message)
		}
	}

	const promptAndCreatePadFromUrl = async () => {
		const values = await openExternalPublicPadDialog()
		if (!values) {
			return
		}
		const trimmedUrl = values.padUrl.trim()
		const name = values.name.trim()
		const dir = getCurrentDir()
		const normalizedName = name.toLowerCase().endsWith('.pad') ? name : (name + '.pad')
		const filePath = normalizeFilePath(dir, normalizedName)

		try {
			const created = await apiCreatePadFromUrl(filePath, trimmedUrl)
			const createdPath = (created && typeof created.file === 'string') ? created.file : filePath
			const createdFileId = created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null
			await openCreatedPadInViewer(
				{
					path: createdPath,
					fileId: createdFileId,
				},
				{
					fallbackOpen: openPadInNativeViewer,
					resolveOpenDir,
				}
			)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not import public pad URL.')
			window.alert(message)
		}
	}

	const promptAndCreatePublicPad = async () => {
		const choice = await openPublicPadModeDialog()
		if (choice === 'internal') {
			await createInternalPublicPad()
			return
		}
		if (choice === 'external') {
			await promptAndCreatePadFromUrl()
		}
	}

	const navigateFilesRouteAndOpen = (fileId, path) => {
		const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
		if (!router || typeof router.goToRoute !== 'function') {
			return false
		}
		if (!fileId || !Number.isFinite(fileId)) {
			return false
		}
		router.goToRoute(
			null,
			{
				view: 'files',
				fileid: String(fileId),
			},
			{
				dir: resolveOpenDir(path),
				editing: 'false',
				openfile: 'true',
			}
		)
		window.setTimeout(() => {
			const hasExpectedPath = (window.location.pathname || '').includes('/apps/files/files/' + String(fileId))
			if (hasExpectedPath) {
				return
			}
			const fallbackUrl = filesUrlForFileId(fileId, path)
			window.location.assign(fallbackUrl)
		}, 180)
		return true
	}

	const openPadInNativeViewer = async (navigation) => {
		const openKey = String(navigation.fileId ?? '') + '|' + String(navigation.path ?? '')
		const now = Date.now()
		if (lastOpenKey === openKey && (now - lastOpenAt) < 800) {
			return
		}
		lastOpenKey = openKey
		lastOpenAt = now

		const publicShareToken = parsePublicShareTokenFromLocation()
		const inPublicShareRoute = publicShareToken !== null && publicShareToken !== ''

		const fallbackOpen = () => {
			if (inPublicShareRoute) {
				window.location.assign(viewerUrlForPublicShare(publicShareToken, navigation.path || ''))
				return
			}
			if (isFilesAppRoute() && navigation.fileId !== null && navigation.fileId !== undefined && Number.isFinite(Number(navigation.fileId))) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(Number(navigation.fileId), fallbackPath))
				return
			}
			const routeFileId = isFilesAppRoute() ? parseFileIdFromCurrentLocation() : null
			if ((navigation.fileId === null || navigation.fileId === undefined) && routeFileId) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(routeFileId, fallbackPath))
				return
			}
			if (navigation.fileId !== null && navigation.fileId !== undefined && Number.isFinite(Number(navigation.fileId))) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(Number(navigation.fileId), fallbackPath))
				return
			}
			if (navigation.path) {
				window.location.assign(viewerUrlForPath(navigation.path))
			}
		}

		if (!hasNativeViewer()) {
			fallbackOpen()
			return
		}

		let path = navigation.path || ''
		let fileId = navigation.fileId ?? null
		if (!path && navigation.fileId !== null && navigation.fileId !== undefined) {
			try {
				const resolvedPad = await apiResolvePadByFileId(navigation.fileId)
				path = (resolvedPad && typeof resolvedPad.path === 'string') ? resolvedPad.path : ''
				fileId = (resolvedPad && Number.isFinite(Number(resolvedPad.file_id))) ? Number(resolvedPad.file_id) : fileId
			} catch (e) {
				path = ''
			}
		}

		if (!path) {
			fallbackOpen()
			return
		}
		if ((!fileId || !Number.isFinite(Number(fileId))) && path && !inPublicShareRoute) {
			try {
				const resolvedPad = await apiResolvePadByPath(path)
				fileId = (resolvedPad && Number.isFinite(Number(resolvedPad.file_id))) ? Number(resolvedPad.file_id) : fileId
			} catch (e) {
				// resolve failure is handled by route fallback below
			}
		}
		if ((!fileId || !Number.isFinite(Number(fileId))) && isFilesAppRoute()) {
			const routeFileId = parseFileIdFromCurrentLocation()
			if (routeFileId) {
				fileId = routeFileId
			}
		}
		if (isFilesAppRoute()) {
			if (fileId && Number.isFinite(Number(fileId)) && navigateFilesRouteAndOpen(Number(fileId), path)) {
				return
			}
			fallbackOpen()
			return
		}

		try {
			window.OCA.Viewer.open({ path })
		} catch (e) {
			fallbackOpen()
		}
	}

	const resolvePublicSharePadPathFromLink = (link, publicToken) => {
		if (!(link instanceof HTMLAnchorElement) || !publicToken) {
			return null
		}
		const href = link.getAttribute('href') || ''
		const publicSharePad = parsePublicSharePadFromHref(href)
		if (publicSharePad && publicSharePad.token === publicToken && isPadName(publicSharePad.path)) {
			return publicSharePad.path
		}
		const davPadPath = parsePadPathFromDavHref(href)
		if (isPadName(davPadPath)) {
			return davPadPath
		}
		return null
	}

	const registerPadClickInterceptor = () => {
		if (window.OCA && window.OCA.EtherpadNextcloudClickInterceptorRegistered === true) {
			return
		}
		if (window.OCA) {
			window.OCA.EtherpadNextcloudClickInterceptorRegistered = true
		}

		const maybeOpenPad = async (event) => {
			if (!event || event.defaultPrevented) {
				return
			}
			const publicToken = parsePublicShareTokenFromLocation()
			if (!publicToken) {
				return
			}
			if (event.type === 'click' && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
				return
			}
			if (!(event.target instanceof Element)) {
				return
			}
			const link = event.target.closest('a[href]')
			if (!(link instanceof HTMLAnchorElement)) {
				return
			}
			const padPath = resolvePublicSharePadPathFromLink(link, publicToken)
			if (!padPath) {
				return
			}
			event.preventDefault()
			event.stopPropagation()
			if (typeof event.stopImmediatePropagation === 'function') {
				event.stopImmediatePropagation()
			}
			if (hasNativeViewer()) {
				await openPadInNativeViewer({ path: padPath, fileId: null })
				return
			}
			window.location.assign(viewerUrlForPublicShare(publicToken, padPath))
		}

		document.addEventListener('click', maybeOpenPad, true)
	}

	const registerOpenAction = () => {
		if (!(window.OCA && window.OCA.Files && window.OCA.Files.fileActions)) {
			return
		}
		window.OCA.Files.fileActions.register(
			MIME,
			OPEN_ACTION,
			ocPermissionRead(),
			ocImagePath(APP_ID, 'etherpad-icon-color'),
			(filename, context) => {
				const dir = (context && context.dir) || getCurrentDir()
				const filePath = normalizeFilePath(dir, filename)
				const fileId = extractFileIdFromActionContext(context)
				void openPadInNativeViewer({ path: filePath, fileId })
			},
			t(APP_ID, 'Open in Etherpad')
		)
		window.OCA.Files.fileActions.setDefault(MIME, OPEN_ACTION)
	}

	const ensurePublicPadMenuRegistration = createPublicPadMenuRegistrar({
		isFilesAppRoute,
		onCreatePublicPad: promptAndCreatePublicPad,
	})

	const installSidebarSyncObserver = () => {
		if (sidebarSyncObserver !== null || !isFilesAppRoute()) {
			return
		}
		const observer = new MutationObserver((records) => {
			for (const record of records) {
				for (const node of record.addedNodes) {
					if (!(node instanceof Element)) {
						continue
					}
					if (node.matches('[data-cy-files-sidebar], #app-sidebar-vue, .app-sidebar')
						|| node.querySelector('[data-cy-files-sidebar], #app-sidebar-vue, .app-sidebar')) {
						scheduleSidebarSyncPanelRefresh(60)
						return
					}
				}
			}
		})
		observer.observe(document.body, { childList: true, subtree: true })
		sidebarSyncObserver = observer
	}

	const maybeRedirectFromFilesRoute = async () => {
		if (hasNativeViewer()) {
			return
		}
		const fileId = parseFileIdFromCurrentLocation()
		if (!fileId) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		if (params.get('epNoRedirect') === '1' || params.get('details') === '1' || params.get('opendetails') === 'true') {
			return
		}
		if ((window.location.pathname || '').includes('/apps/' + APP_ID + '/')) {
			return
		}
		try {
			const resolved = await apiResolvePadByFileId(fileId)
			if (resolved && resolved.is_pad && resolved.viewer_url) {
				window.location.assign(resolved.viewer_url)
			}
		} catch (e) {
			// Ignore and let Files handle non-pad files
		}
	}

	const maybeNormalizeStalePadFileRoute = async () => {
		if (hasNativeViewer()) {
			return
		}
		if (!isFilesAppRoute()) {
			return
		}
		const fileId = parseFileIdFromCurrentLocation()
		if (!fileId) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		if (params.get('openfile') === 'true' || params.get('opendetails') === 'true' || params.get('details') === '1') {
			return
		}
		try {
			const resolved = await apiResolvePadByFileId(fileId)
			if (!resolved || !resolved.is_pad) {
				return
			}
			const dir = params.get('dir') || '/'
			const target = ocGenerateUrl('/apps/files/files') + '?dir=' + encodeURIComponent(dir)
			if (window.location.href !== target) {
				window.location.assign(target)
			}
		} catch (e) {
			// keep current route if resolve fails
		}
	}

	const maybeRedirectFromPublicShareRoute = () => {
		const token = parsePublicShareTokenFromLocation()
		if (!token) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		const fileName = params.get('files') || ''
		if (hasNativeViewer()) {
			if (!isPadName(fileName)) {
				return
			}
			const dir = params.get('path') || '/'
			const filePath = normalizeFilePath(dir, fileName)
			void openPadInNativeViewer({ path: filePath, fileId: null })
			return
		}
		if (!isPadName(fileName)) {
			const isAlreadyInAppViewer = (window.location.pathname || '').includes('/apps/' + APP_ID + '/public/')
			if (isAlreadyInAppViewer) {
				return
			}
			// Only single-file shares can be opened without an explicit file path.
			// For folder shares, an empty redirect causes a first-open 400 ("No .pad file selected").
			if (isSingleFilePublicShare(token)) {
				window.location.assign(viewerUrlForPublicShare(token, ''))
			}
			return
		}
		const dir = params.get('path') || '/'
		const filePath = normalizeFilePath(dir, fileName)
		window.location.assign(viewerUrlForPublicShare(token, filePath))
	}

	const evaluateCurrentRoute = () => {
		const currentKey = (window.location.pathname || '') + '?' + (window.location.search || '')
		if (currentKey === lastRouteCheckKey) {
			return
		}
		lastRouteCheckKey = currentKey
		if (isFilesAppRoute()) {
			ensurePublicPadMenuRegistration()
		}
		schedulePublicSingleShareUiStateRefresh()
		void maybeNormalizeStalePadFileRoute()
		void maybeRedirectFromFilesRoute()
		maybeRedirectFromPublicShareRoute()
		scheduleSidebarSyncPanelRefresh(0)
	}

	const installRouteWatchers = () => {
		const flag = 'EtherpadNextcloudRouteWatchInstalled'
		if (window.OCA && window.OCA[flag] === true) {
			return
		}
		if (window.OCA) {
			window.OCA[flag] = true
		}

		const trigger = () => window.setTimeout(evaluateCurrentRoute, 0)
		window.addEventListener('popstate', trigger)
		window.addEventListener('hashchange', trigger)

		const wrapHistoryMethod = (methodName) => {
			const original = window.history && window.history[methodName]
			if (typeof original !== 'function') {
				return
			}
			window.history[methodName] = function (...args) {
				const result = original.apply(this, args)
				trigger()
				return result
			}
		}
		wrapHistoryMethod('pushState')
		wrapHistoryMethod('replaceState')
	}

	const boot = () => {
		if (booted) {
			return
		}
		booted = true
		installRouteWatchers()
		evaluateCurrentRoute()
		registerOpenAction()
		registerPadClickInterceptor()
		installSidebarSyncObserver()
		scheduleSidebarSyncPanelRefresh(200)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true })
	} else {
		boot()
	}
})()
