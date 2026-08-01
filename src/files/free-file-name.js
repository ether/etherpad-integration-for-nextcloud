/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Nextcloud's own getUniqueName lives inside @nextcloud/files and is not
 * exposed as a global, so the numbering is reproduced here rather than
 * called: plain name first, then "name (2)", "name (3)", counter before the
 * extension. The tests pin that shape.
 *
 * Comparison is case-insensitive because the storage behind Nextcloud
 * usually is, and a name differing only in case would still collide.
 *
 * @param {string} preferredName the name to use when nothing is in the way
 * @param {Iterable<string>} takenNames names already present in the folder
 * @returns {string} a name not among takenNames
 */
export const suggestFreeName = (preferredName, takenNames = []) => {
	const taken = new Set()
	for (const name of takenNames) {
		if (typeof name === 'string') {
			taken.add(name.toLowerCase())
		}
	}
	if (!taken.has(preferredName.toLowerCase())) {
		return preferredName
	}

	// A leading dot belongs to the name, so only split on a later one.
	const dot = preferredName.lastIndexOf('.')
	const base = dot > 0 ? preferredName.slice(0, dot) : preferredName
	const extension = dot > 0 ? preferredName.slice(dot) : ''

	for (let index = 2; index < 1000; index += 1) {
		const candidate = `${base} (${index})${extension}`
		if (!taken.has(candidate.toLowerCase())) {
			return candidate
		}
	}
	// A folder with a thousand of them is not worth looping over. The backend
	// rejects a duplicate anyway and the dialog shows that inline.
	return preferredName
}

/**
 * Pull the folder's file names out of whatever the NewFileMenu handed the
 * handler. The shape differs between Nextcloud versions — Node objects,
 * plain file infos, or nothing at all — so this probes rather than assumes.
 * Finding nothing simply means no suggestion beyond the preferred name.
 *
 * @param {...*} args arguments the menu passed to the handler
 * @returns {string[]} file names found in those arguments
 */
export const fileNamesFromMenuArgs = (...args) => {
	for (const arg of args) {
		if (!Array.isArray(arg)) {
			continue
		}
		const names = arg.map(nameOf).filter((name) => name !== '')
		if (names.length > 0) {
			return names
		}
	}
	return []
}

const nameOf = (entry) => {
	if (typeof entry === 'string') {
		return entry
	}
	if (!entry || typeof entry !== 'object') {
		return ''
	}
	// `basename` on a Node, `name` on the older file info shape.
	return (typeof entry.basename === 'string' && entry.basename)
		|| (typeof entry.name === 'string' && entry.name)
		|| ''
}
