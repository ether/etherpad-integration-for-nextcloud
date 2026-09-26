/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * What a stubbed fetch() resolves with, so each shape of answer is written
 * once.
 */
export const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

export const errorResponse = (body, status = 400) => jsonResponse(body, false, status)

/** A page in place of this app's JSON: a proxy's, a maintenance page, PHP's own. */
export const pageResponse = (status) => ({
	ok: status < 400,
	status,
	json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
})

/** An answer whose body broke off after its status line. */
export const brokenBody = (status) => ({
	ok: status < 400,
	status,
	json: () => Promise.reject(new TypeError('network error')),
})
