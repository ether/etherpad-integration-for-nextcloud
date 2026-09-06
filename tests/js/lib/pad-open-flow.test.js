/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it, vi } from 'vitest'
import {
	assertOpenPayload,
	contentUrlFrom,
	contentViewFrom,
	isMissingFrontmatterError,
	openWithFrontmatterRecovery,
	padUrlFrom,
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

	it('leaves a failed initialise to the caller', async () => {
		const open = vi.fn().mockRejectedValue(withCode('missing_frontmatter'))
		const initialize = vi.fn().mockRejectedValue(new Error('could not write the file'))

		await expect(openWithFrontmatterRecovery({ open, initialize })).rejects.toThrow('could not write the file')
		expect(open).toHaveBeenCalledTimes(1)
	})

	/** The card the viewer shows is built from whatever comes back out. */
	it('leaves a failed second open to the caller', async () => {
		const open = vi.fn()
			.mockRejectedValueOnce(withCode('missing_frontmatter'))
			.mockRejectedValueOnce(withCode('missing_binding'))
		const initialize = vi.fn().mockResolvedValue(undefined)

		await expect(openWithFrontmatterRecovery({ open, initialize })).rejects.toMatchObject({ code: 'missing_binding' })
		expect(open).toHaveBeenCalledTimes(2)
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

	it('will not call home more often than the floor', () => {
		expect(syncSettingsFrom({ sync_interval_seconds: 0.001 }).intervalMs).toBe(5000)
	})

	/** Capping it would ask the server for more than it said it wanted. */
	it('leaves a long interval as long as it was asked for', () => {
		expect(syncSettingsFrom({ sync_interval_seconds: 86400 }).intervalMs).toBe(86400000)
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

describe('padUrlFrom', () => {
	it('hands back the value it validated, not the one it was sent', () => {
		expect(padUrlFrom({ url: '  https://pad.example/p/x  ' })).toBe('https://pad.example/p/x')
		expect(padUrlFrom({})).toBe('')
		expect(padUrlFrom(null)).toBe('')
	})
})

describe('contentViewFrom', () => {
	it('draws an editable pad in its own iframe, not in our view', () => {
		expect(contentViewFrom({ url: 'https://pad.example/p/x' }))
			.toEqual({ isContentView: false, externalUrl: '' })
	})

	it('offers the original of an external pad', () => {
		expect(contentViewFrom({ is_external: true, url: ' https://other.example/p/y ' }))
			.toEqual({ isContentView: true, externalUrl: 'https://other.example/p/y' })
	})

	it('has nothing to offer for an external pad that names none', () => {
		expect(contentViewFrom({ is_external: true, url: '  ' }))
			.toEqual({ isContentView: false, externalUrl: '' })
	})

	/** A snapshot of a pad the reader may not reach has no original to link. */
	it('offers no link from a read-only view, whatever else the payload says', () => {
		expect(contentViewFrom({ is_readonly_view: true }))
			.toEqual({ isContentView: true, externalUrl: '' })
		expect(contentViewFrom({ is_readonly_view: true, is_external: true, url: 'https://other.example/p/y' }))
			.toEqual({ isContentView: true, externalUrl: '' })
	})
})

describe('contentUrlFrom', () => {
	it('reads the endpoint, or nothing at all', () => {
		expect(contentUrlFrom({ content_url: ' /content/42 ' })).toBe('/content/42')
		expect(contentUrlFrom({})).toBe('')
		expect(contentUrlFrom(null)).toBe('')
	})
})
