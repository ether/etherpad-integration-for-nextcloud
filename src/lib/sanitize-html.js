/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import DOMPurify from 'dompurify'

/**
 * Client-side defense-in-depth for pad HTML.
 *
 * The HTML is sanitized server-side already, but it comes from a pad
 * server this app does not own and the viewer injects it via `innerHTML`,
 * so the same allowlist is enforced again here — a regression in the
 * server gate then cannot become XSS.
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
 * Set here rather than trusted from the server pass: a link arriving
 * without them still cannot hand the opened tab a reference back.
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
		// Only what a link needs; the hook above sets target and rel.
		ALLOWED_ATTR: ['href', 'target', 'rel'],
		ALLOWED_URI_REGEXP,
		ALLOW_DATA_ATTR: false,
	})
}
