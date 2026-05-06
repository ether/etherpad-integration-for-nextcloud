/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const escapeAttribute = (value) => String(value || '')
	.replace(/&/g, '&amp;')
	.replace(/"/g, '&quot;')
	.replace(/</g, '&lt;')
	.replace(/>/g, '&gt;')

const ALLOWED_PAD_URL_SCHEMES = ['http:', 'https:']

const isSafePadUrl = (url) => {
	try {
		const parsed = new URL(String(url || ''))
		return ALLOWED_PAD_URL_SCHEMES.includes(parsed.protocol)
	} catch {
		return false
	}
}

const SRC_DOC_CSP = "default-src 'none'; frame-src http: https:; style-src 'unsafe-inline'"

export const buildPadFrameSrcdoc = (url) => {
	const safeUrl = isSafePadUrl(url) ? escapeAttribute(url) : ''
	return '<!doctype html><html><head><meta charset="utf-8">'
	+ '<meta http-equiv="Content-Security-Policy" content="' + escapeAttribute(SRC_DOC_CSP) + '">'
	+ '<style>html,body,iframe{width:100%;height:100%;margin:0;border:0;overflow:hidden}iframe{display:block}</style>'
	+ '</head><body><iframe src="' + safeUrl + '" title="Etherpad"></iframe></body></html>'
}
