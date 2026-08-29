/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import {
	apiResolvePadByFileId,
	apiResolvePadByPath,
} from '../lib/api-client.js'
import {
	getFilesRouter,
	hasNativeViewer,
	ignoreExpectedNavigationResult,
	isFilesAppRoute,
} from '../lib/nextcloud-runtime.js'
import {
	filesUrlForFileId,
	parseFileIdFromCurrentLocation,
	parsePublicShareTokenFromLocation,
	resolveOpenDir,
	viewerUrlForPath,
	viewerUrlForPublicShare,
} from '../lib/urls.js'

const DEDUPE_OPEN_WINDOW_MS = 800
const ROUTE_FALLBACK_DELAY_MS = 180
// Debounce between route push and Viewer.open: Nextcloud's SPA needs a short
// moment to settle the folder state, otherwise the viewer can render against
// the previous folder context or fail to resolve the path.
const ROUTE_OPEN_DELAY_MS = 120

const navigateFilesRouteAndOpen = (fileId, path) => {
	const router = getFilesRouter()
	if (!router) {
		return false
	}
	if (!fileId || !Number.isFinite(fileId)) {
		return false
	}
	let nativeOpenStarted = false
	ignoreExpectedNavigationResult(router.goToRoute(
		null,
		{
			view: 'files',
			fileid: String(fileId),
		},
		{
			dir: resolveOpenDir(path),
		}
	))
	window.setTimeout(() => {
		try {
			const result = window.OCA.Viewer.open({
				path,
				onClose: clearFilesViewerRoute,
			})
			nativeOpenStarted = true
			ignoreExpectedNavigationResult(result)
		} catch (e) {
			// The route fallback below still lets Nextcloud handle viewer opening.
		}
	}, ROUTE_OPEN_DELAY_MS)
	window.setTimeout(() => {
		if (nativeOpenStarted) {
			return
		}
		const fallbackUrl = filesUrlForFileId(fileId, path)
		window.location.assign(fallbackUrl)
	}, ROUTE_FALLBACK_DELAY_MS)
	return true
}

const clearFilesViewerRoute = () => {
	const router = getFilesRouter()
	if (!router) {
		return
	}
	const query = { ...(router.query || {}) }
	delete query.openfile
	delete query.editing
	ignoreExpectedNavigationResult(router.goToRoute(null, router.params || {}, query))
}

export const createPadOpener = () => {
	let lastOpenKey = null
	let lastOpenAt = 0

	return async (navigation) => {
		const openKey = String(navigation.fileId ?? '') + '|' + String(navigation.path ?? '')
		const now = Date.now()
		if (lastOpenKey === openKey && (now - lastOpenAt) < DEDUPE_OPEN_WINDOW_MS) {
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
				// Without the cache: this id decides which document the viewer
				// opens, and since the by-path retry is gone there is nothing
				// downstream to notice a stale one. A five-minute-old
				// path-to-id answer can name a file that has since moved out
				// of the way of another.
				const resolvedPad = await apiResolvePadByPath(path, { bypassCache: true })
				fileId = (resolvedPad && Number.isFinite(Number(resolvedPad.file_id))) ? Number(resolvedPad.file_id) : fileId
			} catch (e) {
				// Resolve failure is handled by route fallback below.
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
			const result = window.OCA.Viewer.open({ path })
			ignoreExpectedNavigationResult(result)
		} catch (e) {
			fallbackOpen()
		}
	}
}
