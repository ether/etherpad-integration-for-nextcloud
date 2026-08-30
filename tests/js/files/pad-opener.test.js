/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Module scope: vi.mock is hoisted regardless of where it is written, so a
// call inside a test would silently apply to the whole file — and Vitest
// warns that writing it there will become an error. The functions are
// configured per test instead.
vi.mock('../../../src/lib/api-client.js', () => ({
	apiResolvePadByFileId: vi.fn(),
	apiResolvePadByPath: vi.fn(),
}))

import { createPadOpener } from '../../../src/files/pad-opener.js'

const { apiResolvePadByFileId, apiResolvePadByPath } = await import('../../../src/lib/api-client.js')

const installFilesRouter = () => {
	const router = {
		params: {},
		query: {},
		goToRoute: vi.fn((route, params = {}, query = {}) => {
			router.params = { ...params }
			router.query = { ...query }
		}),
	}
	window.OCP = { Files: { Router: router } }
	return router
}

let assignSpy

beforeEach(() => {
	apiResolvePadByFileId.mockReset()
	apiResolvePadByPath.mockReset()
	vi.useFakeTimers()
	window.history.replaceState({}, '', '/index.php/apps/files/files?dir=/Current')
	window.OCA = {
		Viewer: {
			open: vi.fn(),
		},
	}
	assignSpy = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete window.OCA
	delete window.OCP
})

describe('pad opener', () => {
	it('opens Files-route pads through the native viewer and clears openfile on close', async () => {
		const router = installFilesRouter()
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await vi.advanceTimersByTimeAsync(120)

		expect(router.goToRoute).toHaveBeenCalledWith(
			null,
			{ view: 'files', fileid: '42' },
			{ dir: '/Folder' }
		)
		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/Test.pad',
			onClose: expect.any(Function),
		}))

		const openOptions = window.OCA.Viewer.open.mock.calls[0][0]
		router.query = { dir: '/Folder', editing: 'false', openfile: 'true' }
		openOptions.onClose()

		expect(router.goToRoute).toHaveBeenLastCalledWith(
			null,
			router.params,
			{ dir: '/Folder' }
		)
	})

	it('swallows expected Files router redirect rejections', async () => {
		const router = installFilesRouter()
		router.goToRoute.mockImplementation((route, params = {}, query = {}) => {
			router.params = { ...params }
			router.query = { ...query }
			return Promise.reject(new Error('Redirected when going via a navigation guard.'))
		})
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await vi.advanceTimersByTimeAsync(120)
		await Promise.resolve()

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/Test.pad',
		}))
	})

	it('swallows expected native viewer navigation rejections', async () => {
		installFilesRouter()
		window.OCA.Viewer.open.mockImplementation(() => Promise.reject(new Error('Redirected when going via a navigation guard.')))
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await vi.advanceTimersByTimeAsync(120)
		await Promise.resolve()

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/Test.pad',
		}))
	})

	it('falls back to the Files auto-open URL when native viewer opening throws', async () => {
		installFilesRouter()
		window.OCA.Viewer.open.mockImplementation(() => {
			throw new Error('Viewer failed to open.')
		})
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await vi.advanceTimersByTimeAsync(180)

		expect(assignSpy).toHaveBeenCalledWith('/index.php/apps/files/files/42?dir=%2FFolder&editing=false&openfile=true')
	})

	// The id in the current route belongs to whatever the route was last at.
	// Pairing it with a path is how one file opens under another's name, and
	// since the viewer no longer retries by path there is nothing downstream
	// to notice.
	it('opens by path when the id lookup fails, not by the id in the route', async () => {
		apiResolvePadByPath.mockRejectedValue(new Error('resolve failed'))
		window.history.replaceState({}, '', '/index.php/apps/files/files/99')
		installFilesRouter()
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: null })
		await vi.advanceTimersByTimeAsync(180)

		// Not /apps/files/files/99 — that id was never checked against the path.
		expect(assignSpy).toHaveBeenCalledWith('/index.php/apps/etherpad_nextcloud/?file=%2FFolder%2FTest.pad')
	})

	it('deduplicates repeated open requests in a short window', async () => {
		const router = installFilesRouter()
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await openPad({ path: '/Folder/Test.pad', fileId: 42 })

		expect(router.goToRoute).toHaveBeenCalledTimes(1)
	})

	it('opens directly with the native viewer outside the Files app', async () => {
		window.history.replaceState({}, '', '/index.php/apps/etherpad_nextcloud/')
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Test.pad' })
	})
})
