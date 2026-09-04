/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { describe, expect, it } from 'vitest'
import {
	isPadName,
	normalizeFilePath,
	parsePublicSharePadFromHref,
	parseFileIdFromCurrentLocation,
	parsePadPathFromDavHref,
	parsePublicSharePadFromHref,
	parsePublicShareTokenFromLocation,
	viewerUrlForPublicShare,
} from '../../../src/lib/urls.js'

const setPathname = (pathname) => {
	window.history.replaceState({}, '', pathname)
}

const setLocation = (pathAndQuery) => {
	window.history.replaceState({}, '', pathAndQuery)
}

describe('path helpers', () => {
	it('normalizes file paths from directory and file name', () => {
		expect(normalizeFilePath('/Folder', 'Test.pad')).toBe('/Folder/Test.pad')
		// The name is joined, never rewritten: " Test .pad" is a name
		// Nextcloud accepts, and changing it here would point at another file.
		expect(normalizeFilePath('/', ' Test .pad')).toBe('/ Test .pad')
		expect(normalizeFilePath('', '/Nested/Test.pad')).toBe('/Nested/Test.pad')
	})

	it('detects pad names case-insensitively', () => {
		expect(isPadName('Test.PAD')).toBe(true)
		expect(isPadName('Test.txt')).toBe(false)
		expect(isPadName(null)).toBe(false)
	})



	// `Folder ` is a name Nextcloud accepts. Trimming the dir sent the open
	// to its neighbour, the same silent substitution as the plus sign.



})

describe('viewer URL builders', () => {
	it('builds public viewer URLs', () => {
		expect(viewerUrlForPublicShare('abc', '')).toBe('/index.php/apps/etherpad_nextcloud/public/abc')
		expect(viewerUrlForPublicShare('abc', '/Shared/Test.pad')).toBe('/index.php/apps/etherpad_nextcloud/public/abc?file=%2FShared%2FTest.pad')
	})
})

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

describe('parseFileIdFromCurrentLocation', () => {
	it('extracts file ids from the current route', () => {
		setPathname('/apps/files/files/321')

		expect(parseFileIdFromCurrentLocation()).toBe(321)
	})
})

describe('parsePublicSharePadFromHref', () => {
	it('extracts pad paths from public download links', () => {
		const href = '/s/share-token/download?path=/Shared&files=Pad.pad'

		expect(parsePublicSharePadFromHref(href)).toEqual({
			token: 'share-token',
			path: '/Shared/Pad.pad',
		})
	})

	it('ignores non-pad public download links', () => {
		const href = '/s/share-token/download?path=/Shared&files=Readme.md'

		expect(parsePublicSharePadFromHref(href)).toBeNull()
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

	it('ignores DAV hrefs with malformed percent encoding', () => {
		const href = 'https://cloud.example.test/remote.php/dav/files/jacob/Folder/%E0%A4%A.pad'

		expect(parsePadPathFromDavHref(href)).toBeNull()
	})
})

describe('query parameters in share links', () => {
	// A query string is form-encoded: `+` means space, and a literal plus
	// is `%2B`. Reading it any other way trades one wrong name for
	// another — `A+B.pad` really does mean `A B.pad`.
	it('reads a plus as a space and %2B as a plus', () => {
		expect(parsePublicSharePadFromHref('https://nc.test/s/tok/download?path=/M&files=A+B.pad'))
			.toEqual({ token: 'tok', path: '/M/A B.pad' })
		expect(parsePublicSharePadFromHref('https://nc.test/s/tok/download?path=/M&files=C%2B%2B.pad'))
			.toEqual({ token: 'tok', path: '/M/C++.pad' })
	})

	it('keeps a name whose spaces sit before the extension', () => {
		expect(parsePublicSharePadFromHref('https://nc.test/s/tok/download?path=/M&files=Notes%20.pad'))
			.toEqual({ token: 'tok', path: '/M/Notes .pad' })
	})
})
