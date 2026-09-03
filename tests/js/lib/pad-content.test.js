// @vitest-environment jsdom
// Same reason as sanitize-html.test.js: happy-dom mis-sanitizes, and these
// cases turn on what DOMPurify actually keeps.
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { loadPadContent } from '../../../src/lib/pad-content.js'

const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

describe('loadPadContent', () => {
	beforeEach(() => {
		globalThis.fetch = vi.fn()
	})

	it('sanitizes what the server sent before it can reach innerHTML', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ html: '<p>ok</p><script>steal()</script>', is_empty: false }))

		const content = await loadPadContent('/content/42')

		expect(content.html).toBe('<p>ok</p>')
		expect(content.isEmpty).toBe(false)
	})

	it('passes the server\'s emptiness verdict through', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ html: '', is_empty: true }))

		expect((await loadPadContent('/content/42')).isEmpty).toBe(true)
	})

	/**
	 * If the browser pass strips everything, there is nothing to show —
	 * and an empty frame with no explanation is the one outcome this view
	 * must not produce.
	 */
	it('treats markup that sanitizes away as empty', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ html: '<script>x</script>', is_empty: false }))

		const content = await loadPadContent('/content/42')

		expect(content.html).toBe('')
		expect(content.isEmpty).toBe(true)
	})

	/**
	 * The failure this catches looks exactly like success. A login page or
	 * a maintenance notice answered with HTTP 200 carries no `html` at
	 * all, and reading a missing field as an empty string would present it
	 * to the reader as an empty pad.
	 */
	it.each([
		['no body at all', null],
		['a maintenance notice', { message: 'maintenance' }],
		['html missing', { is_empty: false }],
		['html not a string', { html: 42, is_empty: false }],
		['is_empty missing', { html: '<p>ok</p>' }],
		['is_empty not a boolean', { html: '<p>ok</p>', is_empty: 'no' }],
	])('refuses %s rather than showing it as an empty pad', async (_label, body) => {
		fetch.mockResolvedValueOnce(jsonResponse(body))

		await expect(loadPadContent('/content/42')).rejects.toThrow('Could not load the pad content.')
	})

	it('reports the server message when the request fails', async () => {
		fetch.mockResolvedValueOnce(jsonResponse({ message: 'Could not load the pad content.' }, false, 502))

		await expect(loadPadContent('/content/42')).rejects.toThrow('Could not load the pad content.')
	})
})
