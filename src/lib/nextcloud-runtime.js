/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const USE_NATIVE_VIEWER = true

export const hasNativeViewer = () => USE_NATIVE_VIEWER
	&& Boolean(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.open === 'function')

export const isFilesAppRoute = () => (window.location.pathname || '').includes('/apps/files')

/**
 * Nextcloud's viewer and router reject on a navigation-guard redirect even
 * though the route change happened. Every other rejection is a real failure
 * the caller has to answer for, which is why this reports rather than
 * swallows.
 */
export const isExpectedNavigationRedirect = (error) => {
	const message = error instanceof Error ? error.message : String(error || '')
	return message.includes('Redirected when going') && message.includes('navigation guard')
}
