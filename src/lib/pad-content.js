/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { fetchJsonWithTimeout } from './fetch-helpers.js'
import { sanitizeSnapshotHtml } from './sanitize-html.js'

/** Longer than the server's own 20s budget, so we cannot time out first. */
const CONTENT_TIMEOUT_MS = 25000

/**
 * Loads the current pad content for a read-only view.
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

	// The failure this catches looks exactly like success: a login page or
	// a maintenance notice answered with HTTP 200 carries no `html`, and a
	// missing field read as `''` would be shown as an empty pad.
	if (!data || typeof data !== 'object' || typeof data.html !== 'string' || typeof data.is_empty !== 'boolean') {
		throw new Error('Could not load the pad content.')
	}

	const html = sanitizeSnapshotHtml(data.html)

	return {
		html,
		// The server's verdict — Etherpad answers an untouched pad with
		// markup, so the string alone cannot say — plus the case it cannot
		// see, a sanitizer that empties what it was given.
		isEmpty: data.is_empty || html.trim() === '',
	}
}
