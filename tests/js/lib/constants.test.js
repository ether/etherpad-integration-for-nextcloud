/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'
import { isPadAccessMode, PAD_ACCESS_MODES } from '../../../src/lib/constants.js'

describe('isPadAccessMode', () => {
	it('knows the two modes', () => {
		expect(PAD_ACCESS_MODES).toEqual(['protected', 'public'])
		expect(isPadAccessMode('protected')).toBe(true)
		expect(isPadAccessMode('public')).toBe(true)
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
