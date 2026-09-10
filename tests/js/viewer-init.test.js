/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers synchronous MIME registration and lazy Viewer component loading. */

import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/viewer', () => ({
	registerHandler: vi.fn(),
}))

const { registerHandler } = await import('@nextcloud/viewer')

let handler
let timeoutSpy

beforeAll(async () => {
	timeoutSpy = vi.spyOn(window, 'setTimeout')
	await import('../../src/viewer-init.js')
	handler = registerHandler.mock.calls[0]?.[0]
})

afterAll(() => {
	timeoutSpy.mockRestore()
})

describe('viewer init', () => {
	it('registers the pad MIME synchronously through the supported API', () => {
		expect(registerHandler).toHaveBeenCalledTimes(1)
		expect(handler).toMatchObject({
			id: 'etherpad_nextcloud',
			mimes: ['application/x-etherpad-nextcloud'],
		})
		expect(timeoutSpy).not.toHaveBeenCalled()
	})

	it('loads the full viewer component only when Nextcloud asks for it', async () => {
		expect(handler.component).toBeTypeOf('function')

		const module = await handler.component()

		expect(module.default.name).toBe('EtherpadNextcloudViewer')
		expect(timeoutSpy).not.toHaveBeenCalled()
	})
})
