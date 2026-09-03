/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { fetchJsonWithTimeout } from './fetch-helpers.js'
import { sanitizeSnapshotHtml } from './sanitize-html.js'

/**
 * Longer than the server's own budget for the fetch (20s for a foreign
 * export). Giving up first would report a timeout while the answer was
 * still on its way.
 */
const CONTENT_TIMEOUT_MS = 25000

/**
 * Loads the current pad content for a read-only view.
 *
 * `isEmpty` comes from the server: Etherpad answers an untouched pad with
 * markup rather than nothing, so the browser cannot tell "empty" from
 * "loaded" by looking at the string.
 *
 * @param {string} contentUrl endpoint from the open response
 * @param {{signal?: AbortSignal}} options
 * @return {Promise<{html: string, isEmpty: boolean}>}
 */
export const loadPadContent = async (contentUrl, { signal } = {}) => {
	const data = await fetchJsonWithTimeout(
		contentUrl,
		{ method: 'GET', signal },
		{ timeoutMs: CONTENT_TIMEOUT_MS, fallbackMessage: 'Could not load the pad content.' },
	)
	const html = sanitizeSnapshotHtml(data && data.html)

	return {
		html,
		// Server first, but a sanitizer that empties the markup means there
		// is nothing to show either — and an empty frame with no explanation
		// is the one outcome this view must not produce.
		isEmpty: (data && data.is_empty === true) || html.trim() === '',
	}
}
