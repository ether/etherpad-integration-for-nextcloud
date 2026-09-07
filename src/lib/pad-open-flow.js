/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * A floor, and the largest delay `setInterval` can hold - a signed 32-bit
 * one, past which it wraps and fires almost at once. The upper bound is
 * the timer's limit, not an opinion on how often to sync: capping a long
 * interval would make the client call home more often than it was asked
 * to.
 */
const MIN_INTERVAL_MS = 5000
const MAX_TIMER_MS = 2147483647
const DEFAULT_INTERVAL_MS = 120000

/**
 * The code, not the message: the wording is translated and reworded.
 *
 * @param {unknown} error
 * @return {boolean}
 */
export const isMissingFrontmatterError = (error) => Boolean(error) && error.code === 'missing_frontmatter'

/**
 * A read-only view carries no pad URL by design; anything else without one
 * would reach an iframe as `undefined`.
 *
 * @param {any} data
 * @return {any}
 */
export const assertOpenPayload = (data) => {
	if (!data || (data.is_readonly_view !== true && padUrlFrom(data) === '')) {
		throw new Error('Pad open API did not return a valid URL.')
	}
	return data
}

/**
 * @param {any} data
 * @return {string}
 */
export const padUrlFrom = (data) => ((data && typeof data.url === 'string') ? data.url.trim() : '')

/**
 * Open a pad, bootstrapping the file first if it has none yet.
 *
 * @param {object} steps
 * @param {() => Promise<any>} steps.open
 * @param {() => Promise<any>} steps.initialize
 * @param {() => boolean} [steps.stillWanted] asked once, after initialising
 * @return {Promise<any>} the payload; null only when a supplied
 *   `stillWanted` returns false
 */
export const openWithFrontmatterRecovery = async ({ open, initialize, stillWanted = () => true }) => {
	try {
		return await open()
	} catch (error) {
		if (!isMissingFrontmatterError(error)) {
			throw error
		}
		// Not abortable: it creates a pad, writes a binding row and
		// rewrites the file.
		await initialize()
		// The open after it mints a session and a cookie, so a caller that
		// has moved on should not pay for one.
		if (!stillWanted()) {
			return null
		}
		return await open()
	}
}

/**
 * @param {any} data
 * @return {{syncUrl: string, intervalMs: number}}
 */
export const syncSettingsFrom = (data) => {
	const seconds = Number(data && data.sync_interval_seconds)
	return {
		syncUrl: (data && typeof data.sync_url === 'string') ? data.sync_url.trim() : '',
		intervalMs: (Number.isFinite(seconds) && seconds > 0)
			? Math.min(MAX_TIMER_MS, Math.max(MIN_INTERVAL_MS, seconds * 1000))
			: DEFAULT_INTERVAL_MS,
	}
}

/**
 * @param {any} data
 * @return {string}
 */
export const contentUrlFrom = (data) => ((data && typeof data.content_url === 'string') ? data.content_url.trim() : '')

/**
 * Whether one of our own views draws this pad, and what it may link to. A
 * read-only view draws a pad the reader may not reach directly, so it has
 * no original to offer; only an external pad carries a link.
 *
 * @param {any} data
 * @return {{isContentView: boolean, externalUrl: string}}
 */
export const contentViewFrom = (data) => {
	const isReadOnly = Boolean(data && data.is_readonly_view === true)
	const url = padUrlFrom(data)
	const isExternal = Boolean(data && data.is_external === true) && url !== ''
	return {
		isContentView: isReadOnly || isExternal,
		externalUrl: (isExternal && !isReadOnly) ? url : '',
	}
}
