/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ignoreExpectedNavigationResult } from '../../../src/lib/nextcloud-runtime.js'

afterEach(() => {
	vi.restoreAllMocks()
})

describe('Nextcloud runtime helpers', () => {
	it('accepts promise-like navigation results without a catch method', () => {
		const thenable = {
			then(resolve) {
				resolve()
			},
		}

		expect(() => ignoreExpectedNavigationResult(thenable)).not.toThrow()
	})

	it('suppresses expected Vue Router navigation guard redirects', async () => {
		const debugSpy = vi.spyOn(window.console, 'debug').mockImplementation(() => {})

		ignoreExpectedNavigationResult(Promise.reject(new Error('Redirected when going from "/files/1" to "/files/2" via a navigation guard.')))
		await Promise.resolve()

		expect(debugSpy).not.toHaveBeenCalled()
	})

	it('debug-logs unexpected navigation rejections', async () => {
		const debugSpy = vi.spyOn(window.console, 'debug').mockImplementation(() => {})
		const error = new Error('Viewer failed for an unexpected reason.')

		ignoreExpectedNavigationResult(Promise.reject(error))
		await Promise.resolve()

		expect(debugSpy).toHaveBeenCalledWith('[etherpad_nextcloud] Unexpected navigation rejection', error)
	})
})
