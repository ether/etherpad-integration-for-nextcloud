/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import DOMPurify from 'dompurify'

/**
 * Client-side defense-in-depth for pad HTML.
 *
 * The HTML is already sanitized server-side by `SnapshotHtmlSanitizer`
 * before it reaches the browser, but it originates on a pad server this
 * app does not own, and that server pass is the sole XSS gate. Since the
 * viewer and embed inject the HTML via `innerHTML`, we run it through
 * DOMPurify with the *same* allowlist the server enforces so a regression
 * in the server gate can't turn into stored XSS.
 *
 * Mirrors `SnapshotHtmlSanitizer::ALLOWED_TAGS` and its link rule.
 */
const ALLOWED_TAGS = [
	'p', 'br',
	'ul', 'ol', 'li',
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	'strong', 'b', 'em', 'i', 'u', 's', 'del',
	'blockquote', 'pre', 'code',
	'a',
]

/** Mirrors `SnapshotHtmlSanitizer::ALLOWED_LINK_SCHEMES`. */
const ALLOWED_URI_REGEXP = /^(?:https?|mailto):/i

/**
 * Set here rather than trusted from the server pass, so the browser stage
 * stands on its own: a link that arrives without them still cannot hand
 * the opened tab a reference back to this one.
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
	if (node.tagName !== 'A') {
		return
	}
	if (!node.hasAttribute('href')) {
		// The href was refused, so this is no longer a link. The server
		// stage unwraps it to plain text; leaving an empty `<a>` shell here
		// would be the two stages disagreeing about the same input.
		node.replaceWith(...node.childNodes)
		return
	}
	node.setAttribute('target', '_blank')
	node.setAttribute('rel', 'noopener noreferrer')
})

/**
 * @param {unknown} html
 * @return {string} sanitized HTML safe to assign to innerHTML
 */
export function sanitizeSnapshotHtml(html) {
	return DOMPurify.sanitize(String(html ?? ''), {
		ALLOWED_TAGS,
		// Only what a link needs. `target` and `rel` are allowed because
		// the hook above sets them; nothing else survives on any tag.
		ALLOWED_ATTR: ['href', 'target', 'rel'],
		ALLOWED_URI_REGEXP,
		ALLOW_DATA_ATTR: false,
	})
}
