/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createPadOpener } from '../../../src/files/pad-opener.js'

const PUBLIC_ROUTE = '/index.php/s/sharetoken123'
// The message Nextcloud's own router rejects with when a navigation guard
// redirects. The route change happened; the rejection is noise.
const GUARD_REDIRECT = 'Redirected when going from "/s/x" to "/s/y" via a navigation guard.'

let assignSpy

const flush = async () => {
	await Promise.resolve()
	await Promise.resolve()
}

beforeEach(() => {
	vi.useFakeTimers()
	window.history.replaceState({}, '', PUBLIC_ROUTE)
	window.OCA = { Viewer: { open: vi.fn() } }
	assignSpy = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete window.OCA
})

describe('pad opener', () => {
	it('opens the path in the native viewer without navigating away', async () => {
		await createPadOpener()('/Folder/Test.pad')

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Test.pad' })
		expect(assignSpy).not.toHaveBeenCalled()
	})

	it('leaves an expected navigation-guard rejection alone', async () => {
		window.OCA.Viewer.open.mockReturnValue(Promise.reject(new Error(GUARD_REDIRECT)))

		await createPadOpener()('/Folder/Test.pad')
		await flush()

		expect(assignSpy).not.toHaveBeenCalled()
	})

	it('falls back to the app public viewer when the open rejects for any other reason', async () => {
		window.OCA.Viewer.open.mockReturnValue(Promise.reject(new Error('no handler for this mimetype')))

		await createPadOpener()('/Folder/Test.pad')
		await flush()

		expect(assignSpy).toHaveBeenCalledTimes(1)
		const url = assignSpy.mock.calls[0][0]
		expect(url).toContain('/apps/etherpad_nextcloud/public/sharetoken123')
		expect(url).toContain('file=' + encodeURIComponent('/Folder/Test.pad'))
	})

	it('falls back when the open throws synchronously', async () => {
		window.OCA.Viewer.open.mockImplementation(() => {
			throw new Error('no viewer')
		})

		await createPadOpener()('/Folder/Test.pad')

		expect(assignSpy).toHaveBeenCalledTimes(1)
		expect(assignSpy.mock.calls[0][0]).toContain('/apps/etherpad_nextcloud/public/sharetoken123')
	})

	it('falls back when the Viewer app is not there at all', async () => {
		delete window.OCA.Viewer

		await createPadOpener()('/Folder/Test.pad')

		expect(assignSpy).toHaveBeenCalledTimes(1)
		expect(assignSpy.mock.calls[0][0]).toContain('/apps/etherpad_nextcloud/public/sharetoken123')
	})

	it('does nothing off a public-share route, where Nextcloud own viewer action opens the pad', async () => {
		window.history.replaceState({}, '', '/index.php/apps/files/files?dir=/Folder')

		await createPadOpener()('/Folder/Test.pad')

		expect(window.OCA.Viewer.open).not.toHaveBeenCalled()
		expect(assignSpy).not.toHaveBeenCalled()
	})

	it('deduplicates repeated open requests inside the window and lets a later one through', async () => {
		const openPad = createPadOpener()

		await openPad('/Folder/Test.pad')
		await openPad('/Folder/Test.pad')

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(1)

		// One step past DEDUPE_OPEN_WINDOW_MS, not two: the assertion should
		// fail if the window is ever widened.
		vi.advanceTimersByTime(801)
		await openPad('/Folder/Test.pad')

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(2)
	})
})
