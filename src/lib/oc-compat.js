/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

export const ocGenerateUrl = (path) => {
	if (window.OC && typeof window.OC.generateUrl === 'function') {
		return window.OC.generateUrl(path)
	}
	return '/index.php' + path
}

export const ocRequestToken = (fallback = '') => {
	const configuredFallback = String(fallback || '').trim()
	if (configuredFallback !== '') {
		return configuredFallback
	}
	return String((window.OC && window.OC.requestToken) || '')
}

// Called as t(APP_ID, 'literal') so the Nextcloud translationtool (xgettext
// --keyword=t:2) extracts the string into the gettext template.
export const t = (app, text) => (typeof window.t === 'function' ? window.t(app, text) : text)
