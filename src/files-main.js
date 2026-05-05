/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME } from './lib/constants.js'
import {
	nextcloudMajorVersion,
	ocGenerateUrl,
	ocImagePath,
	ocPermissionCreate,
	ocPermissionRead,
	ocRequestToken,
} from './lib/oc-compat.js'
import { parsePadPathFromDavHref, parsePublicShareTokenFromLocation } from './lib/urls.js'
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
	let publicPadMenuApiRegistered = false
	let publicPadMenuLegacyApiRegistered = false
	let publicPadMenuLegacyPluginHooked = false
	let publicPadMenuRegistrationToken = 0
	const USE_NATIVE_VIEWER = true
	const SIDEBAR_PANEL_ATTR = 'data-epnc-sidebar-sync-panel'
	const SIDEBAR_PANEL_MOUNT_ATTR = 'data-epnc-sidebar-sync-mount'
	const SIDEBAR_SYNC_STATUS_POLL_BASE_MS = 8000
	const SIDEBAR_SYNC_STATUS_POLL_MAX_MS = 60000
	const SIDEBAR_SYNC_STATUS_POLL_ERROR_MS = 15000
	const SIDEBAR_SYNC_STATUS_POLL_STEP_MS = 8000
	const PUBLIC_PAD_MENU_ENTRY_ID = APP_ID + '_public_pad'
	const PUBLIC_PAD_MENU_ICON_CLASS = 'icon-filetype-etherpad-nextcloud-pad'
	const PUBLIC_PAD_MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" fill-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="2" clip-rule="evenodd" viewBox="0 0 355 355"><path fill="#5ac395" d="M317 89v177c0 28-23 51-51 51H89c-28 0-51-23-51-51V89c0-28 23-51 51-51h177c28 0 51 23 51 51"/><path fill="#4aa57f" fill-rule="nonzero" d="M240 144q-4-3-8 0-6 6 0 9a36 36 0 0 1 0 52 6 6 0 0 0 3 12q3 0 5-3c19-18 19-50 0-70"/><path fill="#479a76" fill-rule="nonzero" d="M267 122q-4-5-9 0-4 4 0 10c25 26 25 68 0 93q-4 5 0 9 5 4 9 0c30-31 30-81 0-112"/><path fill="#fff" d="M192 130v2q-1 8-10 10H76q-9-2-11-10v-2q1-10 11-10h106q9 0 10 10m24 51v2q-1 11-12 11H78q-10 0-12-11v-2q2-10 12-11h126q12 1 12 11"/><path fill="#fff" d="M216 181v2q-1 11-12 11H78q-10 0-12-11v-2q2-10 12-11h126q12 1 12 11m-57 52v1q-1 11-10 11H76q-9 0-11-11v-1q1-10 11-11h73q9 1 10 11"/><path fill="#fff" d="M159 233v1q-1 11-10 11H76q-9 0-11-11v-1q1-10 11-11h73q9 1 10 11"/></svg>'
	const PAD_MENU_ORDER = 98
	const PUBLIC_PAD_MENU_REGISTRATION_MAX_ATTEMPTS = 120
	const PUBLIC_PAD_MENU_REGISTRATION_RETRY_MS = 500
	const supportsInlineNewFileMenuSvg = () => {
		const major = nextcloudMajorVersion()
		return major !== null && major >= 33
	}

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
	const hasNativeViewer = () => USE_NATIVE_VIEWER && Boolean(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.open === 'function')
	const isFilesAppRoute = () => (window.location.pathname || '').includes('/apps/files')
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

	const isDuplicateError = (error) => {
		const message = (error && error.message) ? String(error.message) : ''
		return message.toLowerCase().includes('duplicate')
	}

	const extractCreateCapabilityFromMenuContext = (arg) => {
		if (!arg || typeof arg !== 'object') {
			return null
		}
		if (arg.hasCreatePermission === false) {
			return false
		}
		if (arg.hasCreatePermission === true) {
			return true
		}
		if (typeof arg.canCreate === 'boolean') {
			return arg.canCreate
		}
		const requiredPermission = ocPermissionCreate()
		const permissionCandidates = [
			arg.permissions,
			arg.permission,
			arg.attributes && arg.attributes.permissions,
			typeof arg.get === 'function' ? arg.get('permissions') : null,
		]
		for (const candidate of permissionCandidates) {
			const numeric = Number(candidate)
			if (Number.isFinite(numeric)) {
				return (numeric & requiredPermission) === requiredPermission
			}
		}
		return null
	}

	const canCreateFromMenuContext = (...args) => {
		for (const arg of args) {
			const resolved = extractCreateCapabilityFromMenuContext(arg)
			if (resolved !== null) {
				return resolved
			}
		}
		return true
	}

	const publicPadMenuEnabled = (...args) => canCreateFromMenuContext(...args)
	const publicPadMenuHandler = () => {
		void promptAndCreatePublicPad()
	}

	const buildPublicPadMenuEntry = () => ({
		id: PUBLIC_PAD_MENU_ENTRY_ID,
		displayName: t(APP_ID, 'Public pad'),
		...(supportsInlineNewFileMenuSvg()
			? { iconSvgInline: PUBLIC_PAD_MENU_ICON_SVG }
			: { iconClass: PUBLIC_PAD_MENU_ICON_CLASS }),
		order: PAD_MENU_ORDER,
		enabled: publicPadMenuEnabled,
		handler: publicPadMenuHandler,
	})

	const buildLegacyPublicPadMenuEntry = () => ({
		id: PUBLIC_PAD_MENU_ENTRY_ID,
		displayName: t(APP_ID, 'Public pad'),
		templateName: t(APP_ID, 'Public pad'),
		iconClass: PUBLIC_PAD_MENU_ICON_CLASS,
		...(supportsInlineNewFileMenuSvg() ? { iconSvgInline: PUBLIC_PAD_MENU_ICON_SVG } : {}),
		fileType: 'file',
		order: PAD_MENU_ORDER,
		actionHandler: publicPadMenuHandler,
	})

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

	const suggestFileNameFromPadUrl = (padUrl) => {
		try {
			const url = new URL(padUrl, window.location.origin)
			const decoded = decodeURIComponent(url.pathname || '')
			const match = decoded.match(/\/p\/([^/]+)$/)
			if (match && match[1]) {
				const safe = match[1].replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
				if (safe !== '') {
					return safe + '.pad'
				}
			}
		} catch (e) {
			// fallback below
		}
		return 'Imported pad.pad'
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

	const createModalScaffold = (titleText) => {
		const overlay = document.createElement('div')
		overlay.style.position = 'fixed'
		overlay.style.inset = '0'
		overlay.style.background = 'rgba(0, 0, 0, 0.45)'
		overlay.style.display = 'flex'
		overlay.style.alignItems = 'center'
		overlay.style.justifyContent = 'center'
		overlay.style.zIndex = '20000'

		const dialog = document.createElement('div')
		dialog.style.position = 'relative'
		dialog.style.background = 'var(--color-main-background, #fff)'
		dialog.style.borderRadius = '10px'
		dialog.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.25)'
		dialog.style.padding = '18px'
		dialog.style.width = 'min(460px, calc(100vw - 24px))'

		const closeButton = document.createElement('button')
		closeButton.type = 'button'
		closeButton.setAttribute('aria-label', t(APP_ID, 'Close'))
		closeButton.textContent = '×'
		closeButton.style.position = 'absolute'
		closeButton.style.top = '8px'
		closeButton.style.right = '10px'
		closeButton.style.border = 'none'
		closeButton.style.background = 'transparent'
		closeButton.style.fontSize = '22px'
		closeButton.style.cursor = 'pointer'
		closeButton.style.lineHeight = '1'

		const title = document.createElement('h3')
		title.textContent = titleText
		title.style.margin = '0 26px 10px 0'
		title.style.fontSize = '18px'

		dialog.appendChild(closeButton)
		dialog.appendChild(title)
		overlay.appendChild(dialog)
		document.body.appendChild(overlay)

		return { overlay, dialog, closeButton }
	}

	const openInternalPublicPadDialog = () => new Promise((resolve) => {
		const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'Internal public pad'))

		const nameLabel = document.createElement('label')
		nameLabel.textContent = t(APP_ID, 'File name')
		nameLabel.style.display = 'block'
		nameLabel.style.marginBottom = '6px'

		const nameInput = document.createElement('input')
		nameInput.type = 'text'
		nameInput.value = t(APP_ID, 'Public pad') + '.pad'
		nameInput.style.width = '100%'
		nameInput.style.boxSizing = 'border-box'
		nameInput.style.marginBottom = '12px'

		const error = document.createElement('p')
		error.style.color = 'var(--color-error, #c62828)'
		error.style.margin = '0 0 12px 0'
		error.style.minHeight = '20px'

		const createButton = document.createElement('button')
		createButton.type = 'button'
		createButton.className = 'primary'
		createButton.textContent = t(APP_ID, 'Create')

		const close = (result) => {
			overlay.remove()
			resolve(result)
		}
		closeButton.addEventListener('click', () => close(null))
		overlay.addEventListener('click', (event) => {
			if (event.target === overlay) {
				close(null)
			}
		})
		nameInput.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault()
				createButton.click()
			}
		})
		createButton.addEventListener('click', () => {
			const name = nameInput.value.trim()
			if (name === '') {
				error.textContent = t(APP_ID, 'File name is required.')
				return
			}
			close(name)
		})

		dialog.appendChild(nameLabel)
		dialog.appendChild(nameInput)
		dialog.appendChild(error)
		dialog.appendChild(createButton)
		nameInput.focus()
		nameInput.select()
	})

	const openExternalPublicPadDialog = () => new Promise((resolve) => {
		const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'External public pad (URL)'))

		const urlLabel = document.createElement('label')
		urlLabel.textContent = t(APP_ID, 'External pad URL')
		urlLabel.style.display = 'block'
		urlLabel.style.marginBottom = '6px'

		const urlInput = document.createElement('input')
		urlInput.type = 'url'
		urlInput.value = 'https://'
		urlInput.placeholder = 'https://'
		urlInput.style.width = '100%'
		urlInput.style.boxSizing = 'border-box'
		urlInput.style.marginBottom = '12px'

		const nameLabel = document.createElement('label')
		nameLabel.textContent = t(APP_ID, 'File name')
		nameLabel.style.display = 'block'
		nameLabel.style.marginBottom = '6px'

		const nameInput = document.createElement('input')
		nameInput.type = 'text'
		nameInput.value = 'Imported pad.pad'
		nameInput.style.width = '100%'
		nameInput.style.boxSizing = 'border-box'
		nameInput.style.marginBottom = '12px'

		const error = document.createElement('p')
		error.style.color = 'var(--color-error, #c62828)'
		error.style.margin = '0 0 12px 0'
		error.style.minHeight = '20px'

		const createButton = document.createElement('button')
		createButton.type = 'button'
		createButton.className = 'primary'
		createButton.textContent = t(APP_ID, 'Create')

		const close = (result) => {
			overlay.remove()
			resolve(result)
		}
		closeButton.addEventListener('click', () => close(null))
		overlay.addEventListener('click', (event) => {
			if (event.target === overlay) {
				close(null)
			}
		})
		urlInput.addEventListener('blur', () => {
			const candidate = urlInput.value.trim()
			if (candidate.startsWith('http')) {
				nameInput.value = suggestFileNameFromPadUrl(candidate)
			}
		})
		const submit = () => {
			const padUrl = urlInput.value.trim()
			const name = nameInput.value.trim()
			if (padUrl === '') {
				error.textContent = t(APP_ID, 'External pad URL is required.')
				return
			}
			if (name === '') {
				error.textContent = t(APP_ID, 'File name is required.')
				return
			}
			close({ padUrl, name })
		}
		urlInput.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault()
				submit()
			}
		})
		nameInput.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault()
				submit()
			}
		})
		createButton.addEventListener('click', submit)

		dialog.appendChild(urlLabel)
		dialog.appendChild(urlInput)
		dialog.appendChild(nameLabel)
		dialog.appendChild(nameInput)
		dialog.appendChild(error)
		dialog.appendChild(createButton)
		urlInput.focus()
		urlInput.select()
	})

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
			if (isFilesAppRoute() && createdFileId !== null) {
				window.location.assign(filesUrlForFileId(createdFileId, createdPath))
				return
			}
			await openPadInNativeViewer({
				path: createdPath,
				fileId: createdFileId,
			})
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
			if (isFilesAppRoute() && createdFileId !== null) {
				window.location.assign(filesUrlForFileId(createdFileId, createdPath))
				return
			}
			await openPadInNativeViewer({
				path: createdPath,
				fileId: createdFileId,
			})
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not import public pad URL.')
			window.alert(message)
		}
	}

	const openPublicPadModeDialog = () => new Promise((resolve) => {
		const overlay = document.createElement('div')
		overlay.style.position = 'fixed'
		overlay.style.inset = '0'
		overlay.style.background = 'rgba(0, 0, 0, 0.45)'
		overlay.style.display = 'flex'
		overlay.style.alignItems = 'center'
		overlay.style.justifyContent = 'center'
		overlay.style.zIndex = '20000'

		const dialog = document.createElement('div')
		dialog.style.position = 'relative'
		dialog.style.background = 'var(--color-main-background, #fff)'
		dialog.style.borderRadius = '10px'
		dialog.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.25)'
		dialog.style.padding = '18px'
		dialog.style.width = 'min(420px, calc(100vw - 24px))'

		const title = document.createElement('h3')
		title.textContent = t(APP_ID, 'Create public pad')
		title.style.margin = '0 26px 10px 0'
		title.style.fontSize = '18px'

		const closeButton = document.createElement('button')
		closeButton.type = 'button'
		closeButton.setAttribute('aria-label', t(APP_ID, 'Close'))
		closeButton.textContent = '×'
		closeButton.style.position = 'absolute'
		closeButton.style.top = '8px'
		closeButton.style.right = '10px'
		closeButton.style.border = 'none'
		closeButton.style.background = 'transparent'
		closeButton.style.fontSize = '22px'
		closeButton.style.cursor = 'pointer'
		closeButton.style.lineHeight = '1'

		const hint = document.createElement('p')
		hint.textContent = t(APP_ID, 'Choose how you want to create the public pad.')
		hint.style.margin = '0 0 14px 0'
		hint.style.opacity = '0.8'

		const actions = document.createElement('div')
		actions.style.display = 'grid'
		actions.style.gap = '8px'

		const internalBtn = document.createElement('button')
		internalBtn.type = 'button'
		internalBtn.className = 'primary'
		internalBtn.textContent = t(APP_ID, 'Internal public pad')

		const externalBtn = document.createElement('button')
		externalBtn.type = 'button'
		externalBtn.textContent = t(APP_ID, 'External public pad (URL)')
		const externalBtnIcon = ocImagePath(APP_ID, 'etherpad-icon-color')
		if (externalBtnIcon !== '') {
			externalBtn.style.backgroundImage = `url(${externalBtnIcon})`
		}
		externalBtn.style.backgroundRepeat = 'no-repeat'
		externalBtn.style.backgroundPosition = '12px center'
		externalBtn.style.backgroundSize = '16px 16px'
		externalBtn.style.paddingLeft = '34px'

		const close = (result) => {
			overlay.remove()
			resolve(result)
		}

		closeButton.addEventListener('click', () => close(null))
		internalBtn.addEventListener('click', () => close('internal'))
		externalBtn.addEventListener('click', () => close('external'))
		overlay.addEventListener('click', (event) => {
			if (event.target === overlay) {
				close(null)
			}
		})

		actions.appendChild(internalBtn)
		actions.appendChild(externalBtn)
		dialog.appendChild(closeButton)
		dialog.appendChild(title)
		dialog.appendChild(hint)
		dialog.appendChild(actions)
		overlay.appendChild(dialog)
		document.body.appendChild(overlay)
	})

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

	const resolveNewFileMenuApi = () => {
		const roots = [
			window.OCP && window.OCP.Files,
			window.OCA && window.OCA.Files,
		]
		for (const root of roots) {
			if (!root || typeof root !== 'object') {
				continue
			}
			if (typeof root.addNewFileMenuEntry === 'function') {
				return {
					register: root.addNewFileMenuEntry.bind(root),
					unregister: (typeof root.removeNewFileMenuEntry === 'function') ? root.removeNewFileMenuEntry.bind(root) : null,
				}
			}
			if (typeof root.getNewFileMenu === 'function') {
				let menu = null
				try {
					menu = root.getNewFileMenu()
				} catch (error) {
					menu = null
				}
				if (menu && typeof menu.registerEntry === 'function') {
					return {
						register: menu.registerEntry.bind(menu),
						unregister: (typeof menu.unregisterEntry === 'function') ? menu.unregisterEntry.bind(menu) : null,
					}
				}
			}
		}
		// Internal global fallback used by some Nextcloud builds.
		const globalMenu = window._nc_newfilemenu
		if (globalMenu && typeof globalMenu === 'object' && typeof globalMenu.registerEntry === 'function') {
			return {
				register: globalMenu.registerEntry.bind(globalMenu),
				unregister: (typeof globalMenu.unregisterEntry === 'function') ? globalMenu.unregisterEntry.bind(globalMenu) : null,
			}
		}
		return null
	}

	const registerPublicPadEntryViaNewFileMenuApi = async () => {
		const api = resolveNewFileMenuApi()
		if (!api) {
			return false
		}

		const entry = buildPublicPadMenuEntry()
		try {
			api.register(entry)
			publicPadMenuApiRegistered = true
			return true
		} catch (error) {
			if (isDuplicateError(error)) {
				publicPadMenuApiRegistered = true
				return true
			}
			return false
		}
	}

	const registerPublicPadEntryViaLegacyPluginApi = () => {
		if (publicPadMenuLegacyApiRegistered) {
			return true
		}
		const legacyEntry = buildLegacyPublicPadMenuEntry()
		const tryDirectLegacyMenu = () => {
			const candidates = [
				window.OCA && window.OCA.Files && window.OCA.Files.NewFileMenu,
				window.OCA && window.OCA.Files && window.OCA.Files.newFileMenu,
			]
			for (const menu of candidates) {
				if (!menu || typeof menu !== 'object') {
					continue
				}
				if (typeof menu.addMenuEntry === 'function') {
					try {
						menu.addMenuEntry(legacyEntry)
						return true
					} catch (error) {
						if (isDuplicateError(error)) {
							return true
						}
					}
				}
				if (typeof menu.registerEntry === 'function') {
					try {
						menu.registerEntry(buildPublicPadMenuEntry())
						return true
					} catch (error) {
						if (isDuplicateError(error)) {
							return true
						}
					}
				}
			}
			return false
		}
		try {
			if (tryDirectLegacyMenu()) {
				publicPadMenuLegacyApiRegistered = true
				return true
			}
			if (!publicPadMenuLegacyPluginHooked && window.OC && window.OC.Plugins && typeof window.OC.Plugins.register === 'function') {
				window.OC.Plugins.register('OCA.Files.NewFileMenu', {
					attach(menu) {
						if (!menu || typeof menu.addMenuEntry !== 'function') {
							return
						}
						try {
							menu.addMenuEntry(legacyEntry)
							publicPadMenuLegacyApiRegistered = true
						} catch (error) {
							if (isDuplicateError(error)) {
								publicPadMenuLegacyApiRegistered = true
							}
						}
					},
				})
				publicPadMenuLegacyPluginHooked = true
			}
			// Keep retrying until either direct legacy menu API is available or attach() executed.
			return publicPadMenuLegacyApiRegistered
		} catch (error) {
			if (isDuplicateError(error)) {
				publicPadMenuLegacyApiRegistered = true
				return true
			}
			return false
		}
	}

	const ensurePublicPadMenuRegistration = () => {
		if (!isFilesAppRoute()) {
			return
		}
		if (publicPadMenuApiRegistered || publicPadMenuLegacyApiRegistered) {
			return
		}
		const token = ++publicPadMenuRegistrationToken
		const attempt = async (step) => {
			if (token !== publicPadMenuRegistrationToken || publicPadMenuApiRegistered || publicPadMenuLegacyApiRegistered) {
				return
			}
			const registered = await registerPublicPadEntryViaNewFileMenuApi()
			if (registered) {
				return
			}
			const legacyRegistered = registerPublicPadEntryViaLegacyPluginApi()
			if (legacyRegistered) {
				return
			}
			if (step < PUBLIC_PAD_MENU_REGISTRATION_MAX_ATTEMPTS) {
				window.setTimeout(() => { void attempt(step + 1) }, PUBLIC_PAD_MENU_REGISTRATION_RETRY_MS)
				return
			}
		}
		void attempt(0)
	}

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
