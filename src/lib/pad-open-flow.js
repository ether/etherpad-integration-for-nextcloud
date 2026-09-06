/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * The band a server may ask a client to sync in.
 *
 * The interval decides how often every open pad calls home, so an answer
 * outside this band is taken as a mistake rather than an instruction: a
 * fraction of a second turns each viewer into a load generator, and a very
 * large one turns syncing off without saying so.
 */
const MIN_INTERVAL_MS = 5000
const MAX_INTERVAL_MS = 3600000
const DEFAULT_INTERVAL_MS = 120000

/**
 * Whether the file has no pad metadata yet.
 *
 * The code, not the message: the server's wording is a sentence for a
 * person, and searching it for a phrase breaks the moment anyone
 * translates or rewords it.
 *
 * @param {unknown} error
 * @return {boolean}
 */
export const isMissingFrontmatterError = (error) => Boolean(error) && error.code === 'missing_frontmatter'

/**
 * Return the payload, or refuse one that cannot open anything.
 *
 * A read-only view carries no pad URL by design. Anything else without one
 * would reach an iframe as `undefined` and show a broken frame instead of
 * an error.
 *
 * @param {any} data
 * @return {any}
 */
export const assertOpenPayload = (data) => {
	if (!data || (data.is_readonly_view !== true && (typeof data.url !== 'string' || data.url.trim() === ''))) {
		throw new Error('Pad open API did not return a valid URL.')
	}
	return data
}

/**
 * Open a pad, bootstrapping the file first if it has none yet.
 *
 * @param {object} steps
 * @param {() => Promise<any>} steps.open
 * @param {() => Promise<any>} steps.initialize
 * @param {() => boolean} [steps.stillWanted] asked once, after initialising
 * @return {Promise<any>} the payload, or null when no longer wanted
 */
export const openWithFrontmatterRecovery = async ({ open, initialize, stillWanted = () => true }) => {
	try {
		return await open()
	} catch (error) {
		if (!isMissingFrontmatterError(error)) {
			throw error
		}
		// Deliberately not abortable: it creates a pad, writes a binding
		// row and rewrites the file, and tearing the connection down
		// mid-write leaves the server to finish with nobody left to read
		// the outcome.
		await initialize()
		// But the open after it is worth skipping, because for a
		// protected pad it mints an Etherpad session and a cookie that
		// nothing would ever use.
		if (!stillWanted()) {
			return null
		}
		return await open()
	}
}

/**
 * What the sync loop is told, read out of an open payload.
 *
 * @param {any} data
 * @return {{syncUrl: string, intervalMs: number}}
 */
export const syncSettingsFrom = (data) => {
	const seconds = Number(data && data.sync_interval_seconds)
	return {
		syncUrl: (data && typeof data.sync_url === 'string') ? data.sync_url.trim() : '',
		intervalMs: (Number.isFinite(seconds) && seconds > 0)
			? Math.max(MIN_INTERVAL_MS, Math.min(MAX_INTERVAL_MS, seconds * 1000))
			: DEFAULT_INTERVAL_MS,
	}
}

/**
 * Where a read-only view loads its content from.
 *
 * @param {any} data
 * @return {string}
 */
export const contentUrlFrom = (data) => ((data && typeof data.content_url === 'string') ? data.content_url.trim() : '')
