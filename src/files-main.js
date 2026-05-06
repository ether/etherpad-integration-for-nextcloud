/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID } from './lib/constants.js'
import { ocGenerateUrl } from './lib/oc-compat.js'
import { apiResolvePadByFileId } from './lib/api-client.js'
import { hasNativeViewer, isFilesAppRoute } from './lib/nextcloud-runtime.js'
import {
	isPadName,
	normalizeFilePath,
	parseFileIdFromCurrentLocation,
	parsePadPathFromDavHref,
	parsePublicSharePadFromHref,
	parsePublicShareTokenFromLocation,
	viewerUrlForPublicShare,
} from './lib/urls.js'
import { registerOpenAction } from './files/open-action.js'
import { createPadOpener } from './files/pad-opener.js'
import { createPublicPadCreator } from './files/public-pad-create-flow.js'
import { createSidebarSyncController } from './files/sidebar-sync.js'
import { createPublicPadMenuRegistrar } from './files/public-pad-menu.js'
import { isSingleFilePublicShare, schedulePublicSingleShareUiStateRefresh } from './files/public-single-share-ui.js'

(function () {
	let booted = false
	let lastRouteCheckKey = ''
	const sidebarSync = createSidebarSyncController()
	const openPadInNativeViewer = createPadOpener()
	const promptAndCreatePublicPad = createPublicPadCreator({ openPadInNativeViewer })

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

	const ensurePublicPadMenuRegistration = createPublicPadMenuRegistrar({
		isFilesAppRoute,
		onCreatePublicPad: promptAndCreatePublicPad,
	})

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
		sidebarSync.scheduleRefresh(0)
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
		registerOpenAction({ openPadInNativeViewer })
		registerPadClickInterceptor()
		sidebarSync.installObserver()
		sidebarSync.scheduleRefresh(200)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true })
	} else {
		boot()
	}
})()
