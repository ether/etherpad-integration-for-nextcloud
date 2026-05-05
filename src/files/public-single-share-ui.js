/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { parsePublicShareTokenFromLocation } from '../lib/urls.js'

const PUBLIC_SINGLE_SHARE_ATTR = 'data-epnc-single-file-share'

let publicSingleShareUiRefreshToken = 0

const findPublicShareDownloadLink = (token) => {
	if (!token) {
		return null
	}
	const selector = 'a[href*="/s/' + token + '/download"],a[href*="/index.php/s/' + token + '/download"]'
	const downloadLink = document.querySelector(selector)
	return (downloadLink instanceof HTMLAnchorElement) ? downloadLink : null
}

const readPublicShareViewType = () => {
	const field = document.getElementById('initial-state-files_sharing-view')
	if (!(field instanceof HTMLInputElement)) {
		return ''
	}
	const encoded = String(field.value || '').trim()
	if (encoded === '') {
		return ''
	}
	try {
		const decoded = window.atob(encoded)
		if (decoded.startsWith('"') || decoded.startsWith('{') || decoded.startsWith('[')) {
			const parsed = JSON.parse(decoded)
			return typeof parsed === 'string' ? parsed : ''
		}
		return decoded
	} catch (error) {
		return ''
	}
}

export const isSingleFilePublicShare = (token) => {
	if (!token) {
		return false
	}
	const downloadLink = findPublicShareDownloadLink(token)
	if (!downloadLink) {
		return false
	}
	let url
	try {
		url = new URL(downloadLink.getAttribute('href') || '', window.location.origin)
	} catch (error) {
		return false
	}
	const pathMatch = (url.pathname || '').match(/(?:\/index\.php)?\/s\/([^/]+)\/download\/?$/)
	if (!pathMatch || pathMatch[1] !== token) {
		return false
	}
	return !url.searchParams.has('files')
}

const applyPublicSingleShareUiState = () => {
	const body = document.body
	if (!(body instanceof HTMLElement)) {
		return { retry: true }
	}
	const token = parsePublicShareTokenFromLocation()
	if (!token) {
		body.removeAttribute(PUBLIC_SINGLE_SHARE_ATTR)
		return { retry: false }
	}
	const shareViewType = readPublicShareViewType()
	if (shareViewType === 'public-file-share') {
		body.setAttribute(PUBLIC_SINGLE_SHARE_ATTR, '1')
		return { retry: false }
	}
	if (shareViewType === 'public-share') {
		body.removeAttribute(PUBLIC_SINGLE_SHARE_ATTR)
		return { retry: false }
	}
	const downloadLink = findPublicShareDownloadLink(token)
	if (!downloadLink) {
		body.removeAttribute(PUBLIC_SINGLE_SHARE_ATTR)
		return { retry: true }
	}
	if (isSingleFilePublicShare(token)) {
		body.setAttribute(PUBLIC_SINGLE_SHARE_ATTR, '1')
		return { retry: false }
	}
	body.removeAttribute(PUBLIC_SINGLE_SHARE_ATTR)
	return { retry: false }
}

export const schedulePublicSingleShareUiStateRefresh = () => {
	const currentToken = ++publicSingleShareUiRefreshToken
	const run = (attempt) => {
		if (currentToken !== publicSingleShareUiRefreshToken) {
			return
		}
		const result = applyPublicSingleShareUiState()
		if (result.retry && attempt < 30) {
			window.setTimeout(() => run(attempt + 1), 180)
		}
	}
	run(0)
}
