/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import {
	hasNativeViewer,
	ignoreExpectedNavigationResult,
} from '../lib/nextcloud-runtime.js'
import {
	parsePublicShareTokenFromLocation,
	viewerUrlForPublicShare,
} from '../lib/urls.js'

const DEDUPE_OPEN_WINDOW_MS = 800

/**
 * Opens a pad on a public-share route, which is the only place this app opens
 * one itself: a signed-in user's click is answered by Nextcloud's own Viewer
 * action, against the MIME type viewer-main.js registers. Both callers — the
 * share-link click interceptor and the route controller — read the token out
 * of the location before calling, so a call without one has nowhere to go.
 */
export const createPadOpener = () => {
	let lastOpenKey = null
	let lastOpenAt = 0

	return async (navigation) => {
		const path = navigation.path || ''
		const openKey = String(navigation.fileId ?? '') + '|' + path
		const now = Date.now()
		if (lastOpenKey === openKey && (now - lastOpenAt) < DEDUPE_OPEN_WINDOW_MS) {
			return
		}
		lastOpenKey = openKey
		lastOpenAt = now

		const token = parsePublicShareTokenFromLocation()
		if (!token) {
			return
		}

		// Without a path this lands on the share's own viewer, which is the
		// right destination for a single-file share.
		const openInAppViewer = () => window.location.assign(viewerUrlForPublicShare(token, path))

		if (!hasNativeViewer() || !path) {
			openInAppViewer()
			return
		}

		try {
			ignoreExpectedNavigationResult(window.OCA.Viewer.open({ path }))
		} catch (e) {
			openInAppViewer()
		}
	}
}
