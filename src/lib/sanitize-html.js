/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import DOMPurify from 'dompurify'

/**
 * Client-side defense-in-depth for snapshot HTML.
 *
 * Snapshot HTML stored in `.pad` files is already sanitized server-side by
 * `SnapshotHtmlSanitizer` before it reaches the browser, but `.pad` bodies are
 * attacker-writable (WebDAV / a malicious share) and that server pass is the
 * sole XSS gate. Since the viewer and embed inject the HTML via `innerHTML`,
 * we run it through DOMPurify with the *same* allowlist the server enforces
 * (formatting tags only, no attributes) so a regression in the server gate
 * can't turn into stored XSS.
 *
 * Mirrors `SnapshotHtmlSanitizer::ALLOWED_TAGS`.
 */
const ALLOWED_TAGS = [
	'p', 'br',
	'ul', 'ol', 'li',
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	'strong', 'b', 'em', 'i', 'u', 's', 'del',
	'blockquote', 'pre', 'code',
]

/**
 * @param {unknown} html
 * @return {string} sanitized HTML safe to assign to innerHTML
 */
export function sanitizeSnapshotHtml(html) {
	return DOMPurify.sanitize(String(html ?? ''), {
		ALLOWED_TAGS,
		ALLOWED_ATTR: [],
		// No data URIs, no unknown protocols — there are no attributes anyway.
		ALLOW_DATA_ATTR: false,
	})
}
