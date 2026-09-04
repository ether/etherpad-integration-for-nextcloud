/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { isExpectedNavigationRedirect } from '../../../src/lib/nextcloud-runtime.js'

describe('Nextcloud runtime helpers', () => {
	it('recognises the rejection a Vue Router navigation guard produces', () => {
		expect(isExpectedNavigationRedirect(
			new Error('Redirected when going from "/files/1" to "/files/2" via a navigation guard.'),
		)).toBe(true)
	})

	it('treats every other rejection as a real failure', () => {
		// The caller falls back on these, so a false positive here would turn
		// a failed open into a dead link.
		expect(isExpectedNavigationRedirect(new Error('Viewer failed for an unexpected reason.'))).toBe(false)
		expect(isExpectedNavigationRedirect(new Error('Redirected when going from "/a" to "/b".'))).toBe(false)
		expect(isExpectedNavigationRedirect(new Error('blocked by a navigation guard'))).toBe(false)
	})

	it('survives a rejection that is not an Error', () => {
		expect(isExpectedNavigationRedirect('Redirected when going somewhere via a navigation guard')).toBe(true)
		expect(isExpectedNavigationRedirect(null)).toBe(false)
		expect(isExpectedNavigationRedirect(undefined)).toBe(false)
	})
})
