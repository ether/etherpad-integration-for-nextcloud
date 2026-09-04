/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { isExpectedNavigationRedirect } from '../lib/nextcloud-runtime.js'
import {
	parsePublicShareTokenFromLocation,
	viewerUrlForPublicShare,
} from '../lib/urls.js'

const DEDUPE_OPEN_WINDOW_MS = 800

/**
 * Opens a pad on a public-share route, the only place this app opens one
 * itself: a signed-in user's click is answered by Nextcloud's own Viewer
 * action, against the MIME type viewer-main.js registers.
 *
 * Both callers — the share-link click interceptor and the route controller —
 * read the token out of the location and check for a native viewer before
 * calling, and both hand over a non-empty path. The single failure funnel is
 * the app's own public viewer: the caller has already called preventDefault(),
 * so an open that quietly does nothing leaves a dead link behind.
 */
export const createPadOpener = () => {
	let lastOpenPath = null
	let lastOpenAt = 0

	return async (path) => {
		const now = Date.now()
		if (lastOpenPath === path && (now - lastOpenAt) < DEDUPE_OPEN_WINDOW_MS) {
			return
		}
		lastOpenPath = path
		lastOpenAt = now

		const token = parsePublicShareTokenFromLocation()
		if (!token) {
			return
		}

		const openInAppViewer = () => window.location.assign(viewerUrlForPublicShare(token, path))

		try {
			const result = window.OCA.Viewer.open({ path })
			// Not awaited: Viewer.open may only settle once the viewer closes,
			// and the caller should not be held open for that. The fallback
			// still fires whenever the rejection lands.
			if (result && typeof result.then === 'function') {
				Promise.resolve(result).catch((error) => {
					if (!isExpectedNavigationRedirect(error)) {
						openInAppViewer()
					}
				})
			}
		} catch (e) {
			// Covers a missing Viewer app too: OCA.Viewer.open then throws.
			openInAppViewer()
		}
	}
}
