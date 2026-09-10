/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

describe('public share bootstrap', () => {
	beforeAll(async () => {
		await import('../../src/public-share-main.js')
	})

	beforeEach(() => {
		window.OCA = { Viewer: { open: vi.fn() } }
		window.history.replaceState({}, '', '/s/share-token')
	})

	it('opens the shared file with the native viewer context', async () => {
		document.dispatchEvent(new Event('DOMContentLoaded'))

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/' })
	})

	it('hands an existing folder-share direct link to the native viewer once', () => {
		window.history.replaceState(
			{},
			'',
			'/s/share-token?path=%2FFolder%2FSub&files=A%2BB.pad',
		)

		document.dispatchEvent(new Event('DOMContentLoaded'))

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(1)
		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Sub/A+B.pad' })
	})
})
