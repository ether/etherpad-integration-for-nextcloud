/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'
import { isPadAccessMode, PAD_ACCESS_MODES } from '../../../src/lib/constants.js'

describe('isPadAccessMode', () => {
	// Not a third copy of the list: PadAccessModeTest holds this one to the
	// PHP enum, and asserting it again here would only agree with itself.
	it('accepts every mode the list names, and nothing outside it', () => {
		expect(PAD_ACCESS_MODES.length).toBeGreaterThan(0)
		for (const mode of PAD_ACCESS_MODES) {
			expect(isPadAccessMode(mode)).toBe(true)
		}
	})

	it('refuses anything else, whatever its type', () => {
		expect(isPadAccessMode('Public')).toBe(false)
		expect(isPadAccessMode('external')).toBe(false)
		expect(isPadAccessMode('')).toBe(false)
		expect(isPadAccessMode(undefined)).toBe(false)
		expect(isPadAccessMode(null)).toBe(false)
		expect(isPadAccessMode(['public'])).toBe(false)
	})
})
