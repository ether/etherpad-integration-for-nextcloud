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

	it('forces sync with request token', async () => {
		const { apiSyncByFileId } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ status: 'updated' }))

		await apiSyncByFileId(5, true)

		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/etherpad_nextcloud/api/v1/pads/sync/5?force=1',
			expect.objectContaining({
				method: 'POST',
				headers: expect.objectContaining({
					requesttoken: 'token-123',
				}),
			})
		)
	})

	it('creates external public pads as form requests', async () => {
		const { apiCreatePadFromUrl } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ file_id: 9 }))

		await apiCreatePadFromUrl('/Folder/Imported.pad', 'https://pad.example.test/p/foo')

		const [, options] = fetch.mock.calls[0]
		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/etherpad_nextcloud/api/v1/pads/from-url')
		expect(options.method).toBe('POST')
		expect(options.headers['Content-Type']).toBe('application/x-www-form-urlencoded;charset=UTF-8')
		expect(options.body).toBe('file=%2FFolder%2FImported.pad&padUrl=https%3A%2F%2Fpad.example.test%2Fp%2Ffoo')
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

	it('attaches the response code to thrown errors', async () => {
		const { apiSyncStatusByFileId } = await importClient()
		fetch.mockResolvedValueOnce(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false))

		try {
			await apiSyncStatusByFileId(99)
			throw new Error('should have thrown')
		} catch (error) {
			expect(error.message).toBe('no binding')
			expect(error.code).toBe('missing_binding')
		}
	})

	it('uses fallback messages for non-json errors', async () => {
		const { apiSyncStatusByFileId } = await importClient()
		fetch.mockResolvedValueOnce({
			ok: false,
			json: () => Promise.reject(new Error('invalid json')),
		})

		await expect(apiSyncStatusByFileId(10)).rejects.toThrow('Sync status check failed.')
	})
})
