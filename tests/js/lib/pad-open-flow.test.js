/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it, vi } from 'vitest'
import {
	assertOpenPayload,
	contentUrlFrom,
	isMissingFrontmatterError,
	openWithFrontmatterRecovery,
	syncSettingsFrom,
} from '../../../src/lib/pad-open-flow.js'

const withCode = (code) => Object.assign(new Error('nope'), { code })

describe('isMissingFrontmatterError', () => {
	it('reads the code, not the sentence written for a person', () => {
		expect(isMissingFrontmatterError(withCode('missing_frontmatter'))).toBe(true)
		expect(isMissingFrontmatterError(new Error('This .pad file has no pad metadata yet.'))).toBe(false)
		expect(isMissingFrontmatterError(withCode('missing_binding'))).toBe(false)
		expect(isMissingFrontmatterError(null)).toBe(false)
	})
})

describe('assertOpenPayload', () => {
	it('refuses a payload that names no pad', () => {
		expect(() => assertOpenPayload(null)).toThrow('did not return a valid URL')
		expect(() => assertOpenPayload({})).toThrow('did not return a valid URL')
		expect(() => assertOpenPayload({ url: '   ' })).toThrow('did not return a valid URL')
	})

	it('lets a read-only view through, which carries no pad URL by design', () => {
		const data = { is_readonly_view: true }
		expect(assertOpenPayload(data)).toBe(data)
	})

	it('returns the payload it was given', () => {
		const data = { url: 'https://pad.example/p/x' }
		expect(assertOpenPayload(data)).toBe(data)
	})
})

describe('openWithFrontmatterRecovery', () => {
	it('opens once when the file already has its metadata', async () => {
		const open = vi.fn().mockResolvedValue({ url: 'u' })
		const initialize = vi.fn()

		await expect(openWithFrontmatterRecovery({ open, initialize })).resolves.toEqual({ url: 'u' })
		expect(initialize).not.toHaveBeenCalled()
	})

	it('initialises and opens again when the file has none', async () => {
		const open = vi.fn()
			.mockRejectedValueOnce(withCode('missing_frontmatter'))
			.mockResolvedValueOnce({ url: 'u' })
		const initialize = vi.fn().mockResolvedValue(undefined)

		await expect(openWithFrontmatterRecovery({ open, initialize })).resolves.toEqual({ url: 'u' })
		expect(open).toHaveBeenCalledTimes(2)
		expect(initialize).toHaveBeenCalledTimes(1)
	})

	it('leaves every other failure to the caller', async () => {
		const open = vi.fn().mockRejectedValue(withCode('missing_binding'))
		const initialize = vi.fn()

		await expect(openWithFrontmatterRecovery({ open, initialize })).rejects.toThrow('nope')
		expect(initialize).not.toHaveBeenCalled()
	})

	/** That second open mints an Etherpad session and a cookie for nobody. */
	it('does not open again once the caller has moved on', async () => {
		const open = vi.fn().mockRejectedValueOnce(withCode('missing_frontmatter'))
		const initialize = vi.fn().mockResolvedValue(undefined)

		const data = await openWithFrontmatterRecovery({ open, initialize, stillWanted: () => false })

		expect(data).toBeNull()
		expect(initialize).toHaveBeenCalledTimes(1)
		expect(open).toHaveBeenCalledTimes(1)
	})
})

describe('syncSettingsFrom', () => {
	it('takes the interval the server asked for', () => {
		expect(syncSettingsFrom({ sync_url: '/sync/42', sync_interval_seconds: 60 }))
			.toEqual({ syncUrl: '/sync/42', intervalMs: 60000 })
	})

	it('holds an interval to a band, whichever end it falls off', () => {
		expect(syncSettingsFrom({ sync_interval_seconds: 0.001 }).intervalMs).toBe(5000)
		expect(syncSettingsFrom({ sync_interval_seconds: 31536000 }).intervalMs).toBe(3600000)
	})

	it('falls back when there is no usable interval', () => {
		expect(syncSettingsFrom({}).intervalMs).toBe(120000)
		expect(syncSettingsFrom({ sync_interval_seconds: 0 }).intervalMs).toBe(120000)
		expect(syncSettingsFrom({ sync_interval_seconds: -5 }).intervalMs).toBe(120000)
		expect(syncSettingsFrom({ sync_interval_seconds: 'soon' }).intervalMs).toBe(120000)
		expect(syncSettingsFrom(null).intervalMs).toBe(120000)
	})

	it('reads no URL as no syncing', () => {
		expect(syncSettingsFrom({}).syncUrl).toBe('')
		expect(syncSettingsFrom({ sync_url: '  ' }).syncUrl).toBe('')
		expect(syncSettingsFrom({ sync_url: ' /sync/42 ' }).syncUrl).toBe('/sync/42')
	})
})

describe('contentUrlFrom', () => {
	it('reads the endpoint, or nothing at all', () => {
		expect(contentUrlFrom({ content_url: ' /content/42 ' })).toBe('/content/42')
		expect(contentUrlFrom({})).toBe('')
		expect(contentUrlFrom(null)).toBe('')
	})
})
