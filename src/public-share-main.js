/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

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

document.addEventListener('DOMContentLoaded', () => {
	window.OCA.Viewer.open({ path: selectedPadPath() || '/' })
})
