/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import {
	apiResolvePadByFileId,
	apiResolvePadByPath,
} from '../lib/api-client.js'
import { hasNativeViewer, isFilesAppRoute } from '../lib/nextcloud-runtime.js'
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
		try {
			window.OCA.Viewer.open({
				path,
				onClose: clearFilesViewerRoute,
			})
		} catch (e) {
			// The route fallback below still lets Nextcloud handle viewer opening.
		}
	}, ROUTE_OPEN_DELAY_MS)
	window.setTimeout(() => {
		const hasExpectedPath = (window.location.pathname || '').includes('/apps/files/files/' + String(fileId))
		if (hasExpectedPath) {
			return
		}
		const fallbackUrl = filesUrlForFileId(fileId, path)
		window.location.assign(fallbackUrl)
	}, ROUTE_FALLBACK_DELAY_MS)
	return true
}

const clearFilesViewerRoute = () => {
	const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
	if (!router || typeof router.goToRoute !== 'function') {
		return
	}
	const query = { ...(router.query || {}) }
	delete query.openfile
	delete query.editing
	router.goToRoute(null, router.params || {}, query)
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
				const resolvedPad = await apiResolvePadByPath(path)
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
			window.OCA.Viewer.open({ path })
		} catch (e) {
			fallbackOpen()
		}
	}
}
