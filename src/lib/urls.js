/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

export const isPadName = (name) => typeof name === 'string' && name.toLowerCase().endsWith('.pad')

export const parsePublicShareTokenFromLocation = () => {
	const match = (window.location.pathname || '').match(/(?:\/index\.php)?\/s\/([^/]+)(?:\/.*)?$/)
	if (!match) {
		return null
	}
	return match[1] || null
}

export const parsePadPathFromDavHref = (href) => {
	if (!href || typeof href !== 'string') {
		return null
	}
	let url
	try {
		url = new URL(href, window.location.origin)
	} catch (error) {
		return null
	}
	let pathname
	try {
		pathname = decodeURIComponent(url.pathname || '')
	} catch (error) {
		return null
	}
	// Same rule as the PHP side, which accepts `.PAD` from a desktop
	// client or a WebDAV upload.
	if (!isPadName(pathname)) {
		return null
	}
	const markers = ['/remote.php/dav/files/', '/public.php/dav/files/']
	const marker = markers.find((candidate) => pathname.includes(candidate))
	if (!marker) return null
	const markerIndex = pathname.indexOf(marker)
	const rest = pathname.substring(markerIndex + marker.length)
	const firstSlash = rest.indexOf('/')
	if (firstSlash === -1) {
		return null
	}
	return '/' + rest.substring(firstSlash + 1)
}
