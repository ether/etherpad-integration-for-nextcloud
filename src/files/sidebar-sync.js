/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID } from '../lib/constants.js'
import { ocImagePath } from '../lib/oc-compat.js'
import {
	apiResolvePadByFileId,
	apiSyncByFileId,
	apiSyncStatusByFileId,
} from '../lib/api-client.js'
import { isFilesAppRoute } from '../lib/nextcloud-runtime.js'
import {
	parseFileIdFromCurrentLocation,
	parseFileIdFromFilesHref,
	parsePublicShareTokenFromLocation,
} from '../lib/urls.js'

const SIDEBAR_PANEL_ATTR = 'data-epnc-sidebar-sync-panel'
const SIDEBAR_PANEL_MOUNT_ATTR = 'data-epnc-sidebar-sync-mount'
const SIDEBAR_SYNC_STATUS_POLL_BASE_MS = 8000
const SIDEBAR_SYNC_STATUS_POLL_MAX_MS = 60000
const SIDEBAR_SYNC_STATUS_POLL_ERROR_MS = 15000
const SIDEBAR_SYNC_STATUS_POLL_STEP_MS = 8000

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

const parseNumericFileId = (value) => {
	const id = Number(value)
	return Number.isFinite(id) && id > 0 ? id : null
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

export const computeNextSidebarSyncPollDelay = (status, currentDelayMs) => {
	if (status === 'synced') {
		return Math.min(SIDEBAR_SYNC_STATUS_POLL_MAX_MS, currentDelayMs + SIDEBAR_SYNC_STATUS_POLL_STEP_MS)
	}
	if (status === 'pending') {
		return SIDEBAR_SYNC_STATUS_POLL_BASE_MS
	}
	return SIDEBAR_SYNC_STATUS_POLL_ERROR_MS
}

export const createSidebarSyncController = () => {
	let sidebarSyncObserver = null
	let sidebarSyncRefreshTimer = null
	let sidebarSyncStatusPollTimer = null
	let sidebarSyncStatusPollFileId = null
	let sidebarSyncRefreshToken = 0

	const clearSidebarSyncStatusPoll = () => {
		if (sidebarSyncStatusPollTimer !== null) {
			window.clearTimeout(sidebarSyncStatusPollTimer)
			sidebarSyncStatusPollTimer = null
		}
		sidebarSyncStatusPollFileId = null
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

	const scheduleRefresh = (delayMs = 0) => {
		clearSidebarSyncRefreshTimer()
		sidebarSyncRefreshTimer = window.setTimeout(() => {
			sidebarSyncRefreshTimer = null
			void refreshSidebarSyncPanel()
		}, Math.max(0, delayMs))
	}

	const installObserver = () => {
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
						scheduleRefresh(60)
						return
					}
				}
			}
		})
		observer.observe(document.body, { childList: true, subtree: true })
		sidebarSyncObserver = observer
	}

	return {
		installObserver,
		scheduleRefresh,
	}
}
