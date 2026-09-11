/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Opens public file shares in Nextcloud's native Viewer. */

import { isPadName } from './lib/urls.js'

export const selectedPadPath = (location = window.location) => {
	const params = new URLSearchParams(location.search)
	const fileName = params.get('files')
	if (!isPadName(fileName)) {
		return null
	}

	const cleanName = fileName.replace(/^\/+/, '')
	const rawDirectory = params.get('path') || '/'
	const cleanDirectory = rawDirectory === '/'
		? ''
		: `/${rawDirectory.replace(/^\/+|\/+$/g, '')}`

	return `${cleanDirectory}/${cleanName}`
}

const openSelectedPad = () => {
	// The script is emitted even where the Viewer app is off: addScript's
	// third argument orders scripts, it does not require one.
	if (typeof window.OCA?.Viewer?.open !== 'function') {
		return
	}
	try {
		const opened = window.OCA.Viewer.open({ path: selectedPadPath() || '/' })
		if (opened && typeof opened.catch === 'function') {
			opened.catch(() => {})
		}
	} catch {
		// Nothing to fall back to, and an unhandled rejection on a share
		// page helps nobody.
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', openSelectedPad, { once: true })
} else {
	openSelectedPad()
}
