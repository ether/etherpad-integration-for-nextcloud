/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers registration through both the supported API and older Viewers. */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/viewer', () => ({ registerHandler: vi.fn() }))

const { registerHandler } = await import('@nextcloud/viewer')

const importInit = async () => {
	vi.resetModules()
	registerHandler.mockClear()
	await import('../../src/viewer-init.js')
}

beforeEach(() => {
	vi.useFakeTimers()
	delete window.OCA
})

afterEach(() => {
	vi.useRealTimers()
	delete window.OCA
})

describe('viewer init', () => {
	it('registers the pad MIME through the supported API', async () => {
		await importInit()

		expect(registerHandler).toHaveBeenCalledTimes(1)
		const handler = registerHandler.mock.calls[0][0]
		expect(handler).toMatchObject({
			id: 'etherpad_nextcloud',
			mimes: ['application/x-etherpad-nextcloud'],
		})
	})

	/**
	 * The Viewer assigns its Mime mixin onto what it is handed and registers
	 * it under `component.name`; a loader function takes neither.
	 */
	it('hands over the component itself, not a loader for it', async () => {
		await importInit()

		const { component } = registerHandler.mock.calls[0][0]
		expect(component).toBeTypeOf('object')
		expect(component.name).toBe('EtherpadNextcloudViewer')
	})

	/** Before 31.0.9 the Viewer never reads the global registerHandler writes. */
	it('also registers with a Viewer that is already running', async () => {
		const legacyRegister = vi.fn()
		window.OCA = { Viewer: { registerHandler: legacyRegister, availableHandlers: [] } }

		await importInit()

		expect(legacyRegister).toHaveBeenCalledTimes(1)
		expect(legacyRegister.mock.calls[0][0].id).toBe('etherpad_nextcloud')
	})

	/** A newer Viewer copies the global in, so registering again would duplicate. */
	it('leaves a handler the running Viewer already knows alone', async () => {
		const legacyRegister = vi.fn()
		window.OCA = {
			Viewer: {
				registerHandler: legacyRegister,
				availableHandlers: [{ id: 'etherpad_nextcloud' }],
			},
		}

		await importInit()

		expect(legacyRegister).not.toHaveBeenCalled()
	})

	it('waits for a Viewer that has not loaded yet, and gives up eventually', async () => {
		await importInit()

		const legacyRegister = vi.fn()
		window.OCA = { Viewer: { registerHandler: legacyRegister, availableHandlers: [] } }
		await vi.advanceTimersByTimeAsync(500)

		expect(legacyRegister).toHaveBeenCalledTimes(1)
	})

	it('stops retrying instead of polling forever', async () => {
		await importInit()

		await vi.advanceTimersByTimeAsync(500 * 25)

		expect(vi.getTimerCount()).toBe(0)
	})
})
