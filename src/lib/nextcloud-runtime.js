/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const USE_NATIVE_VIEWER = true

export const hasNativeViewer = () => USE_NATIVE_VIEWER
	&& Boolean(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.open === 'function')

export const isFilesAppRoute = () => (window.location.pathname || '').includes('/apps/files')

export const getFilesRouter = () => {
	const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
	return router && typeof router.goToRoute === 'function' ? router : null
}

const isExpectedNavigationRedirect = (error) => {
	const message = error instanceof Error ? error.message : String(error || '')
	return message.includes('Redirected when going') && message.includes('navigation guard')
}

export const ignoreExpectedNavigationResult = (result) => {
	if (!result || typeof result.then !== 'function') {
		return
	}
	// Nextcloud's router/viewer can reject on expected navigation guard redirects.
	// The route change still happened; only suppress that known Vue Router noise.
	Promise.resolve(result).catch((error) => {
		if (isExpectedNavigationRedirect(error)) {
			return
		}
		if (window.console && typeof window.console.debug === 'function') {
			window.console.debug('[etherpad_nextcloud] Unexpected navigation rejection', error)
		}
	})
}
