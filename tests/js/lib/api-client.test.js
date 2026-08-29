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
	it('resolves pads by file ID and caches in-flight requests', async () => {
		const { apiResolvePadByFileId } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))

		const first = apiResolvePadByFileId(42)
		const second = apiResolvePadByFileId(42)

		await expect(first).resolves.toEqual({ is_pad: true, file_id: 42 })
		await expect(second).resolves.toEqual({ is_pad: true, file_id: 42 })
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/resolve?fileId=42',
			expect.objectContaining({
				method: 'GET',
				credentials: 'same-origin',
			})
		)
	})

	it('drops failed resolve requests from cache', async () => {
		const { apiResolvePadByFileId } = await importClient()
		fetch
			.mockResolvedValueOnce(jsonResponse({ message: 'Nope' }, false))
			.mockResolvedValueOnce(jsonResponse({ is_pad: true }))

		await expect(apiResolvePadByFileId(7)).rejects.toThrow('Nope')
		await expect(apiResolvePadByFileId(7)).resolves.toEqual({ is_pad: true })
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('limits resolve cache growth', async () => {
		const { apiResolvePadByFileId } = await importClient()
		fetch.mockImplementation((url) => Promise.resolve(jsonResponse({ url })))

		for (let fileId = 1; fileId <= 51; fileId += 1) {
			await apiResolvePadByFileId(fileId)
		}
		await apiResolvePadByFileId(1)

		expect(fetch).toHaveBeenCalledTimes(52)
	})

	it('resolves pads by encoded file path', async () => {
		const { apiResolvePadByPath } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true }))

		await apiResolvePadByPath('/Folder/Öffentliches Pad.pad')

		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/resolve?file=%2FFolder%2F%C3%96ffentliches%20Pad.pad',
			expect.any(Object)
		)
	})

	// Recovery writes. A cached path-to-id answer is up to five minutes old,
	// and in five minutes the file can have moved and another .pad taken its
	// place — the write would then land on the wrong document.
	it('bypasses the cache when asked, and refreshes it with the new answer', async () => {
		const { apiResolvePadByPath } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		expect((await apiResolvePadByPath('/x.pad')).file_id).toBe(42)

		// The file moved and a different .pad took its place.
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 77 }))
		expect((await apiResolvePadByPath('/x.pad', { bypassCache: true })).file_id).toBe(77)
		expect(fetch).toHaveBeenCalledTimes(2)

		// And the stale entry is gone rather than lingering behind the fresh answer.
		expect((await apiResolvePadByPath('/x.pad')).file_id).toBe(77)
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	// The viewer resolves by path when it has no file id, so the path-keyed
	// entry is a second copy of the same pre-recovery answer — but only that
	// one path, not every path the session has looked up.
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

		// The recovered path is asked again...
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		await apiResolvePadByPath('/copy.pad')
		expect(fetch).toHaveBeenCalledTimes(4)

		// ...while an unrelated one is still served from the cache.
		await apiResolvePadByPath('/unrelated.pad')
		expect(fetch).toHaveBeenCalledTimes(4)
	})

	it('posts a recovery request and invalidates the resolve cache on success', async () => {
		const { apiRecoverFromSnapshot, apiResolvePadByFileId } = await importClient()
		// Seed the resolve cache so we can verify it is dropped after recover.
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		await apiResolvePadByFileId(42)

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

		// Cache invalidated: the next resolve must hit fetch again.
		fetch.mockResolvedValueOnce(jsonResponse({ is_pad: true, file_id: 42 }))
		await apiResolvePadByFileId(42)
		expect(fetch).toHaveBeenCalledTimes(3)
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
