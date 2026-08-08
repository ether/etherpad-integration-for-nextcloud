/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'

export const ocGenerateUrl = (path) => {
	if (window.OC && typeof window.OC.generateUrl === 'function') {
		return window.OC.generateUrl(path)
	}
	return '/index.php' + path
}

export const ocImagePath = (app, asset) => {
	if (window.OC && typeof window.OC.imagePath === 'function') {
		return window.OC.imagePath(app, asset)
	}
	return ''
}

export const ocRequestToken = (fallback = '') => {
	const configuredFallback = String(fallback || '').trim()
	if (configuredFallback !== '') {
		return configuredFallback
	}
	return String((window.OC && window.OC.requestToken) || '')
}

export const ocPermissionRead = () => {
	const value = window.OC && window.OC.PERMISSION_READ
	const numeric = Number(value)
	return Number.isFinite(numeric) && numeric > 0 ? numeric : 1
}

export const translate = (text) => (typeof window.t === 'function' ? window.t(APP_ID, text) : text)
