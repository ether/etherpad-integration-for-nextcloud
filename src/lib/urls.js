/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'
import { ocGenerateUrl } from './oc-compat.js'

export const normalizeFilePath = (dir, filename) => {
	const cleanDir = !dir || dir === '/' ? '' : String(dir)
	const cleanName = String(filename || '').replace(/^\/+/, '').replace(/\s+\.pad$/i, '.pad')
	if (cleanDir === '') {
		return '/' + cleanName
	}
	return cleanDir + '/' + cleanName
}

export const isPadName = (name) => typeof name === 'string' && name.toLowerCase().endsWith('.pad')

export const getDirFromPath = (path) => {
	if (!path || typeof path !== 'string') {
		return '/'
	}
	const idx = path.lastIndexOf('/')
	if (idx <= 0) {
		return '/'
	}
	return path.slice(0, idx) || '/'
}

export const getCurrentDir = () => {
	const params = new URLSearchParams(window.location.search || '')
	const dir = (params.get('dir') || '/').trim()
	return dir === '' ? '/' : dir
}

export const resolveOpenDir = (path) => {
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

export const viewerUrlForPath = (path) => ocGenerateUrl('/apps/' + APP_ID + '/?file=' + encodeURIComponent(path))

export const filesUrlForFileId = (fileId, path) => {
	const base = ocGenerateUrl('/apps/files/files/' + encodeURIComponent(String(fileId)))
	const params = new URLSearchParams()
	params.set('dir', resolveOpenDir(path))
	params.set('editing', 'false')
	params.set('openfile', 'true')
	return base + '?' + params.toString()
}

export const viewerUrlForPublicShare = (token, path) => {
	const base = ocGenerateUrl('/apps/' + APP_ID + '/public/' + encodeURIComponent(token))
	if (!path) {
		return base
	}
	return base + '?file=' + encodeURIComponent(path)
}

export const parsePublicShareTokenFromLocation = () => {
	const match = (window.location.pathname || '').match(/(?:\/index\.php)?\/s\/([^/]+)(?:\/.*)?$/)
	if (!match) {
		return null
	}
	return match[1] || null
}

export const parseFileIdFromFilesHref = (href) => {
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

export const parseFileIdFromCurrentLocation = () => {
	const match = (window.location.pathname || '').match(/\/apps\/files\/files\/(\d+)\/?$/)
	if (!match) {
		return null
	}
	const id = parseInt(match[1], 10)
	return Number.isFinite(id) && id > 0 ? id : null
}

export const parsePublicSharePadFromHref = (href) => {
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
	if (!pathname.endsWith('.pad')) {
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
