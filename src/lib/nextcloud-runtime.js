/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const USE_NATIVE_VIEWER = true

export const hasNativeViewer = () => USE_NATIVE_VIEWER
	&& Boolean(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.open === 'function')

export const isFilesAppRoute = () => (window.location.pathname || '').includes('/apps/files')
