/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { openCreatedPadInViewer } from '../../../src/files/created-pad-opener.js'

const installFilesRouter = () => {
	const router = {
		params: { view: 'files' },
		query: { dir: '/Current' },
		goToRoute: vi.fn((route, params = {}, query = {}) => {
			router.params = { ...params }
			router.query = { ...query }
		}),
	}
	window.OCP = { Files: { Router: router } }
	return router
}

beforeEach(() => {
	vi.useFakeTimers()
	window.history.replaceState({}, '', '/index.php/apps/files/files?dir=/Current')
	window.OC = {
		getCurrentUser: () => ({ uid: 'jacob' }),
		linkToRemoteBase: () => '/remote.php/dav',
	}
	window._nc_event_bus = {
		emit: vi.fn(),
	}
	window.OCA = {
		Viewer: {
			open: vi.fn(),
		},
	}
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete window.OC
	delete window.OCA
	delete window.OCP
	delete window._nc_event_bus
})

describe('created pad opener', () => {
	it('notifies Files, opens through the native viewer, and clears openfile on close', async () => {
		const router = installFilesRouter()
		const pendingOpen = openCreatedPadInViewer(
			{ path: '/Folder/New.pad', fileId: 55 },
			{ resolveOpenDir: () => '/Folder' }
		)

		await vi.advanceTimersByTimeAsync(120)
		expect(router.goToRoute).toHaveBeenCalledWith(
			null,
			{ view: 'files', fileid: '55' },
			{ dir: '/Folder' },
			true
		)

		await vi.advanceTimersByTimeAsync(900)
		await pendingOpen

		expect(window._nc_event_bus.emit).toHaveBeenCalledWith(
			'editor:file:created',
			'http://localhost:3000/remote.php/dav/files/jacob/Folder/New.pad'
		)
		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/New.pad',
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

		const pendingOpen = openCreatedPadInViewer(
			{ path: '/Folder/New.pad', fileId: 55 },
			{ resolveOpenDir: () => '/Folder' }
		)

		await vi.advanceTimersByTimeAsync(120)
		await vi.advanceTimersByTimeAsync(900)
		await pendingOpen
		await Promise.resolve()

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/New.pad',
		}))
	})

	it('swallows expected native viewer navigation rejections', async () => {
		installFilesRouter()
		window.OCA.Viewer.open.mockImplementation(() => Promise.reject(new Error('Redirected when going via a navigation guard.')))

		const pendingOpen = openCreatedPadInViewer(
			{ path: '/Folder/New.pad', fileId: 55 },
			{ resolveOpenDir: () => '/Folder' }
		)

		await vi.advanceTimersByTimeAsync(120)
		await vi.advanceTimersByTimeAsync(900)
		await pendingOpen
		await Promise.resolve()

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/New.pad',
		}))
	})

	it('falls back when the native viewer is unavailable', async () => {
		delete window.OCA.Viewer
		const fallbackOpen = vi.fn()

		await openCreatedPadInViewer(
			{ path: '/Folder/New.pad', fileId: 55 },
			{ fallbackOpen }
		)

		expect(fallbackOpen).toHaveBeenCalledWith({ path: '/Folder/New.pad', fileId: 55 })
	})
})
