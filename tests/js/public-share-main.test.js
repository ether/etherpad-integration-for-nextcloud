/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers public-share Viewer startup and legacy folder-share selections. */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const setReadyState = (value) => {
	Object.defineProperty(document, 'readyState', { value, configurable: true })
}

const importBootstrap = async () => {
	vi.resetModules()
	await import('../../src/public-share-main.js')
}

beforeEach(() => {
	window.OCA = { Viewer: { open: vi.fn() } }
	window.history.replaceState({}, '', '/s/share-token')
	setReadyState('complete')
})

afterEach(() => {
	delete window.OCA
})

describe('public share bootstrap', () => {
	it('opens the shared file with the native viewer context', async () => {
		await importBootstrap()

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/' })
	})

	it('hands an existing folder-share direct link to the native viewer once', async () => {
		window.history.replaceState({}, '', '/s/share-token?path=%2FFolder%2FSub&files=A%2BB.pad')

		await importBootstrap()

		expect(window.OCA.Viewer.open).toHaveBeenCalledTimes(1)
		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Sub/A+B.pad' })
	})

	/**
	 * The Viewer takes its handlers on DOMContentLoaded, so opening before
	 * that finds none and it closes itself again. Neither of these states is
	 * past that event.
	 */
	it.each(['loading', 'interactive'])('waits for the Viewer when the document is %s', async (state) => {
		setReadyState(state)

		await importBootstrap()
		expect(window.OCA.Viewer.open).not.toHaveBeenCalled()

		document.dispatchEvent(new Event('DOMContentLoaded'))
		expect(window.OCA.Viewer.open).toHaveBeenCalledOnce()
	})

	/** The event has been and gone by then, so waiting would wait forever. */
	it('opens straight away once the page is complete', async () => {
		setReadyState('complete')

		await importBootstrap()

		expect(window.OCA.Viewer.open).toHaveBeenCalledOnce()
	})

	/** addScript's third argument orders scripts; it does not require the app. */
	it('does nothing when the Viewer app is not installed', async () => {
		delete window.OCA

		await expect(importBootstrap()).resolves.not.toThrow()
	})

	it('swallows a refused open rather than leaving a rejection unhandled', async () => {
		window.OCA.Viewer.open = vi.fn(() => Promise.reject(new Error('no handler')))

		await importBootstrap()
		await Promise.resolve()

		expect(window.OCA.Viewer.open).toHaveBeenCalledOnce()
	})

	it('survives an open that throws outright', async () => {
		window.OCA.Viewer.open = vi.fn(() => { throw new Error('router guard') })

		await expect(importBootstrap()).resolves.not.toThrow()
	})
})
