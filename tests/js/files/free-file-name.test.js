/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'
import { fileNamesFromMenuArgs, suggestFreeName } from '../../../src/files/free-file-name.js'

describe('suggestFreeName', () => {
	it('keeps the preferred name when nothing is in the way', () => {
		expect(suggestFreeName('Public pad.pad', ['Notes.pad'])).toBe('Public pad.pad')
	})

	it('numbers from two, like the Files app does', () => {
		expect(suggestFreeName('Public pad.pad', ['Public pad.pad'])).toBe('Public pad (2).pad')
		expect(suggestFreeName('Public pad.pad', ['Public pad.pad', 'Public pad (2).pad']))
			.toBe('Public pad (3).pad')
	})

	it('fills a gap in the series rather than counting past it', () => {
		expect(suggestFreeName('Public pad.pad', ['Public pad.pad', 'Public pad (3).pad']))
			.toBe('Public pad (2).pad')
	})

	it('puts the counter before the extension', () => {
		expect(suggestFreeName('Public pad.pad', ['Public pad.pad'])).not.toContain('.pad (2)')
	})

	/** Most storage backends behind Nextcloud are case-insensitive. */
	it('treats names differing only in case as taken', () => {
		expect(suggestFreeName('Public pad.pad', ['public PAD.pad'])).toBe('Public pad (2).pad')
	})

	it('handles a name without an extension', () => {
		expect(suggestFreeName('Notes', ['Notes'])).toBe('Notes (2)')
	})

	/** A leading dot belongs to the name, so it must not be split off. */
	it('does not treat a dotfile as all extension', () => {
		expect(suggestFreeName('.hidden', ['.hidden'])).toBe('.hidden (2)')
	})

	it('ignores non-string entries', () => {
		expect(suggestFreeName('Public pad.pad', [null, undefined, 42, 'Public pad.pad']))
			.toBe('Public pad (2).pad')
	})
})

describe('fileNamesFromMenuArgs', () => {
	/**
	 * What the NewFileMenu passes differs between Nextcloud versions, so the
	 * shapes below are probed rather than assumed. Finding nothing is fine —
	 * it just means no suggestion beyond the preferred name.
	 */
	it('reads Node-like objects', () => {
		const names = fileNamesFromMenuArgs({ path: '/' }, [{ basename: 'a.pad' }, { basename: 'b.pad' }])
		expect(names).toEqual(['a.pad', 'b.pad'])
	})

	it('reads plain file infos and bare strings', () => {
		expect(fileNamesFromMenuArgs([{ name: 'a.pad' }, 'b.pad']))
			.toEqual(['a.pad', 'b.pad'])
	})

	it('returns nothing when no argument carries a list', () => {
		expect(fileNamesFromMenuArgs()).toEqual([])
		expect(fileNamesFromMenuArgs({ path: '/' }, null, 'x')).toEqual([])
		expect(fileNamesFromMenuArgs([{ id: 1 }])).toEqual([])
	})
})
