/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const importClient = async () => {
	vi.resetModules()
	return import('../../../src/lib/api-client.js')
}

beforeEach(() => {
	window.OC = {
		generateUrl: (path) => '/index.php' + path,
		requestToken: 'token-123',
	}
	globalThis.fetch = vi.fn()
})

afterEach(() => {
	vi.restoreAllMocks()
	delete window.OC
	delete globalThis.fetch
})

const jsonResponse = (body, ok = true) => ({
	ok,
	json: () => Promise.resolve(body),
})

describe('api-client', () => {
	it('resolves pads by encoded file path', async () => {
		const { apiResolvePadByPath } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true }))

		await apiResolvePadByPath('/Folder/Öffentliches Pad.pad')

		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/resolve?file=%2FFolder%2F%C3%96ffentliches%20Pad.pad',
			expect.any(Object)
		)
	})

	// A write must not trust a cached path-to-id mapping.
	it('bypasses the cache when asked, and refreshes it with the new answer', async () => {
		const { apiResolvePadByPath } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		expect((await apiResolvePadByPath('/x.pad')).file_id).toBe(42)

		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 77 }))
		expect((await apiResolvePadByPath('/x.pad', { bypassCache: true })).file_id).toBe(77)
		expect(fetch).toHaveBeenCalledTimes(2)

		expect((await apiResolvePadByPath('/x.pad')).file_id).toBe(77)
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('sends the recovery without a timeout', async () => {
		vi.useFakeTimers()
		const { apiRecoverFromSnapshot } = await importClient()
		let settle
		// Model fetch's abort behavior so an accidental timeout rejects the request.
		fetch.mockImplementation((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))
			settle = () => resolve(jsonResponse({ status: 'restored' }))
		}))

		const pending = apiRecoverFromSnapshot(42, '/copy.pad')
		await vi.advanceTimersByTimeAsync(120_000)
		settle()

		await expect(pending).resolves.toEqual({ status: 'restored' })
		vi.useRealTimers()
	})

	it('drops the recovered path entry and leaves unrelated ones alone', async () => {
		const { apiRecoverFromSnapshot, apiResolvePadByPath } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		await apiResolvePadByPath('/copy.pad')
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 7 }))
		await apiResolvePadByPath('/unrelated.pad')
		expect(fetch).toHaveBeenCalledTimes(2)

		fetch.mockResolvedValueOnce(jsonResponse({ status: 'restored' }))
		await apiRecoverFromSnapshot(42, '/copy.pad')
		expect(fetch).toHaveBeenCalledTimes(3)

		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		await apiResolvePadByPath('/copy.pad')
		expect(fetch).toHaveBeenCalledTimes(4)

		await apiResolvePadByPath('/unrelated.pad')
		expect(fetch).toHaveBeenCalledTimes(4)
	})

	it('posts a recovery request', async () => {
		const { apiRecoverFromSnapshot } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ status: 'restored', new_pad_id: 'fresh' }))

		const result = await apiRecoverFromSnapshot(42)

		expect(result).toEqual({ status: 'restored', new_pad_id: 'fresh' })
		expect(fetch).toHaveBeenLastCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/recover-from-snapshot/42',
			expect.objectContaining({
				method: 'POST',
				headers: expect.objectContaining({
					requesttoken: 'token-123',
				}),
			})
		)

	})

	it('looks up the original pad by file ID with a GET request', async () => {
		const { apiFindOriginalPad } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ found: true, file_id: 42, viewer_url: '/x' }))

		const result = await apiFindOriginalPad(700)

		expect(result).toEqual({ found: true, file_id: 42, viewer_url: '/x' })
		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/find-original/700',
			expect.objectContaining({ method: 'GET' })
		)
	})

	it('attaches the response code to thrown errors', async () => {
		const { apiRecoverFromSnapshot } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false))

		try {
			await apiRecoverFromSnapshot(99)
			throw new Error('should have thrown')
		} catch (error) {
			expect(error.message).toBe('no binding')
			expect(error.code).toBe('missing_binding')
		}
	})

	it('uses fallback messages for non-json errors', async () => {
		const { apiRecoverFromSnapshot } = await importClient()
		fetch.mockResolvedValueOnce({
			ok: false,
			json: () => Promise.reject(new Error('invalid json')),
		})

		await expect(apiRecoverFromSnapshot(10)).rejects.toThrow('Recovery failed.')
	})
})
