/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { computeNextSidebarSyncPollDelay } from '../../../src/files/sidebar-sync.js'

describe('sidebar sync polling', () => {
	it('backs off synced pads until the maximum delay', () => {
		expect(computeNextSidebarSyncPollDelay('synced', 8000)).toBe(16000)
		expect(computeNextSidebarSyncPollDelay('synced', 56000)).toBe(60000)
	})

	it('checks pending pads at the base interval', () => {
		expect(computeNextSidebarSyncPollDelay('pending', 60000)).toBe(8000)
	})

	it('uses the error interval for unavailable or unknown states', () => {
		expect(computeNextSidebarSyncPollDelay('unavailable', 8000)).toBe(15000)
		expect(computeNextSidebarSyncPollDelay('error', 8000)).toBe(15000)
	})
})
