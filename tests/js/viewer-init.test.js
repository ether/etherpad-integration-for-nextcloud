/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers registration of the pad MIME handler. */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/viewer', () => ({ registerHandler: vi.fn() }))

const { registerHandler } = await import('@nextcloud/viewer')

const importInit = async () => {
	vi.resetModules()
	registerHandler.mockClear()
	await import('../../src/viewer-init.js')
}

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
})
