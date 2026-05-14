/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createRouteController } from '../../../src/files/route-controller.js'

const jsonResponse = (body) => Promise.resolve({
	ok: true,
	json: () => Promise.resolve(body),
})

const createController = (overrides = {}) => createRouteController({
	ensurePublicPadMenuRegistration: vi.fn(),
	openPadInNativeViewer: vi.fn(),
	schedulePublicSingleShareUiStateRefresh: vi.fn(),
	...overrides,
})

let assignSpy

beforeEach(() => {
	window.history.replaceState({}, '', '/index.php/apps/files/files/42?dir=/Folder')
	window.OC = {
		generateUrl: (path) => '/index.php' + path,
		requestToken: 'token',
	}
	window.OCA = {}
	global.fetch = vi.fn()
	assignSpy = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	delete window.OC
	delete window.OCA
	delete global.fetch
})

describe('route controller', () => {
	it('redirects Files pad routes to the app viewer when native viewer is unavailable', async () => {
		window.history.replaceState({}, '', '/index.php/apps/files/files/42?dir=/Folder&openfile=true')
		global.fetch.mockReturnValueOnce(jsonResponse({
			is_pad: true,
			viewer_url: '/index.php/apps/etherpad_nextcloud/?file=%2FFolder%2FPad.pad',
		}))
		const controller = createController()

		controller.evaluateCurrentRoute()

		await vi.waitFor(() => {
			expect(assignSpy).toHaveBeenCalledWith('/index.php/apps/etherpad_nextcloud/?file=%2FFolder%2FPad.pad')
		})
	})

	it('normalizes stale Files pad detail routes back to their folder', async () => {
		global.fetch.mockReturnValueOnce(jsonResponse({
			is_pad: true,
			viewer_url: '/index.php/apps/etherpad_nextcloud/?file=%2FFolder%2FPad.pad',
		}))
		const controller = createController()

		controller.evaluateCurrentRoute()

		await vi.waitFor(() => {
			expect(assignSpy).toHaveBeenCalledWith('/index.php/apps/files/files?dir=%2FFolder')
		})
	})

	it('opens public share pad links through the native viewer', () => {
		window.history.replaceState({}, '', '/index.php/s/token123?path=/Shared&files=Pad.pad')
		window.OCA.Viewer = {
			open: vi.fn(),
		}
		const openPadInNativeViewer = vi.fn()
		const controller = createController({ openPadInNativeViewer })

		controller.evaluateCurrentRoute()

		expect(openPadInNativeViewer).toHaveBeenCalledWith({
			path: '/Shared/Pad.pad',
			fileId: null,
		})
	})
})
