/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { describe, expect, it } from 'vitest'
import {
	filesUrlForFileId,
	getCurrentDir,
	getDirFromPath,
	isPadName,
	normalizeFilePath,
	parseFileIdFromCurrentLocation,
	parseFileIdFromFilesHref,
	parsePadPathFromDavHref,
	parsePublicSharePadFromHref,
	parsePublicShareTokenFromLocation,
	resolveOpenDir,
	viewerUrlForPath,
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
		expect(normalizeFilePath('/', ' Test .pad')).toBe('/ Test.pad')
		expect(normalizeFilePath('', '/Nested/Test.pad')).toBe('/Nested/Test.pad')
	})

	it('detects pad names case-insensitively', () => {
		expect(isPadName('Test.PAD')).toBe(true)
		expect(isPadName('Test.txt')).toBe(false)
		expect(isPadName(null)).toBe(false)
	})

	it('extracts directories from paths', () => {
		expect(getDirFromPath('/Folder/Test.pad')).toBe('/Folder')
		expect(getDirFromPath('/Test.pad')).toBe('/')
		expect(getDirFromPath('')).toBe('/')
	})

	it('reads the current Files dir from query params', () => {
		setLocation('/apps/files/files/123?dir=/Folder')

		expect(getCurrentDir()).toBe('/Folder')
	})

	it('uses the current Files dir when opening root-level paths', () => {
		setLocation('/apps/files/files/123?dir=/Current')

		expect(resolveOpenDir('/Test.pad')).toBe('/Current')
		expect(resolveOpenDir('/Folder/Test.pad')).toBe('/Folder')
	})
})

describe('viewer URL builders', () => {
	it('builds internal viewer URLs', () => {
		expect(viewerUrlForPath('/Folder/Test.pad')).toBe('/index.php/apps/etherpad_nextcloud/?file=%2FFolder%2FTest.pad')
	})

	it('builds Files viewer URLs', () => {
		setLocation('/apps/files/files/123?dir=/Current')

		expect(filesUrlForFileId(42, '/Folder/Test.pad')).toBe('/index.php/apps/files/files/42?dir=%2FFolder&editing=false&openfile=true')
	})

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

describe('parseFileIdFromFilesHref', () => {
	it('extracts file ids from Files routes', () => {
		expect(parseFileIdFromFilesHref('/apps/files/files/123')).toBe(123)
	})

	it('extracts file ids from /f routes and query params', () => {
		expect(parseFileIdFromFilesHref('/f/456')).toBe(456)
		expect(parseFileIdFromFilesHref('/apps/files?fileid=789')).toBe(789)
	})

	it('returns null for invalid file id hrefs', () => {
		expect(parseFileIdFromFilesHref('/apps/files/files/nope')).toBeNull()
		expect(parseFileIdFromFilesHref('https://[invalid')).toBeNull()
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
