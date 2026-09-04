/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createPadOpener } from '../../../src/files/pad-opener.js'

const PUBLIC_ROUTE = '/index.php/s/sharetoken123'
let assignSpy

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
		await createPadOpener()({ path: '/Folder/Test.pad', fileId: null })

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Test.pad' })
		expect(assignSpy).not.toHaveBeenCalled()
	})

	it('swallows the navigation rejection Viewer.open resolves with', async () => {
		const rejection = Promise.reject(new Error('Navigation cancelled'))
		window.OCA.Viewer.open.mockReturnValue(rejection)

		await expect(createPadOpener()({ path: '/Folder/Test.pad', fileId: null })).resolves.toBeUndefined()
		await expect(rejection.catch(() => 'handled')).resolves.toBe('handled')
	})

	it('falls back to the app public viewer when the native viewer throws', async () => {
		window.OCA.Viewer.open.mockImplementation(() => {
			throw new Error('no viewer')
		})

		await createPadOpener()({ path: '/Folder/Test.pad', fileId: null })

		expect(assignSpy).toHaveBeenCalledTimes(1)
		const url = assignSpy.mock.calls[0][0]
		expect(url).toContain('/apps/etherpad_nextcloud/public/sharetoken123')
		expect(url).toContain('file=' + encodeURIComponent('/Folder/Test.pad'))
	})

	it('falls back to the app public viewer when there is no native viewer at all', async () => {
		delete window.OCA.Viewer

		await createPadOpener()({ path: '/Folder/Test.pad', fileId: null })

		expect(assignSpy).toHaveBeenCalledTimes(1)
		expect(assignSpy.mock.calls[0][0]).toContain('/apps/etherpad_nextcloud/public/sharetoken123')
	})

	it('sends a pathless open to the share own viewer', async () => {
		await createPadOpener()({ path: '', fileId: null })

		expect(window.OCA.Viewer.open).not.toHaveBeenCalled()
		expect(assignSpy).toHaveBeenCalledTimes(1)
		expect(assignSpy.mock.calls[0][0]).not.toContain('file=')
	})

	it('does nothing off a public-share route, where Nextcloud own viewer action opens the pad', async () => {
		window.history.replaceState({}, '', '/index.php/apps/files/files?dir=/Folder')

		await createPadOpener()({ path: '/Folder/Test.pad', fileId: null })

		expect(window.OCA.Viewer.open).not.toHaveBeenCalled()
		expect(assignSpy).not.toHaveBeenCalled()
	})

	it('deduplicates repeated open requests in a short window', async () => {
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: null })
		await openPad({ path: '/Folder/Test.pad', fileId: null })

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(1)

		vi.advanceTimersByTime(1_000)
		vi.setSystemTime(Date.now() + 1_000)
		await openPad({ path: '/Folder/Test.pad', fileId: null })

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(2)
	})
})
