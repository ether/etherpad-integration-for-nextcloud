/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { describe, expect, it } from 'vitest'
import { parsePadPathFromDavHref, parsePublicShareTokenFromLocation } from '../../../src/lib/urls.js'

const setPathname = (pathname) => {
	window.history.replaceState({}, '', pathname)
}

describe('parsePublicShareTokenFromLocation', () => {
	it('extracts tokens from index.php public share routes', () => {
		setPathname('/index.php/s/share-token')

		expect(parsePublicShareTokenFromLocation()).toBe('share-token')
	})

	it('extracts tokens from pretty public share routes', () => {
		setPathname('/s/share-token/download')

		expect(parsePublicShareTokenFromLocation()).toBe('share-token')
	})

	it('returns null outside public share routes', () => {
		setPathname('/apps/files/files/123')

		expect(parsePublicShareTokenFromLocation()).toBeNull()
	})
})

describe('parsePadPathFromDavHref', () => {
	it('extracts user DAV pad paths', () => {
		const href = 'https://cloud.example.test/remote.php/dav/files/jacob/Folder/Test.pad'

		expect(parsePadPathFromDavHref(href)).toBe('/Folder/Test.pad')
	})

	it('extracts public DAV pad paths', () => {
		const href = 'https://cloud.example.test/public.php/dav/files/token/Shared/Test.pad'

		expect(parsePadPathFromDavHref(href)).toBe('/Shared/Test.pad')
	})

	it('decodes escaped path segments', () => {
		const href = 'https://cloud.example.test/remote.php/dav/files/jacob/G%20-%20Jacobs/%C3%96ffentliches%20Pad.pad'

		expect(parsePadPathFromDavHref(href)).toBe('/G - Jacobs/Öffentliches Pad.pad')
	})

	it('ignores non-pad DAV hrefs', () => {
		const href = 'https://cloud.example.test/remote.php/dav/files/jacob/Folder/Test.txt'

		expect(parsePadPathFromDavHref(href)).toBeNull()
	})

	it('ignores malformed hrefs', () => {
		expect(parsePadPathFromDavHref('https://[invalid')).toBeNull()
	})
})
