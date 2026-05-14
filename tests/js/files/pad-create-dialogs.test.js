/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
	openExternalPublicPadDialog,
	openInternalPublicPadDialog,
} from '../../../src/files/pad-create-dialogs.js'

beforeEach(() => {
	document.body.innerHTML = ''
	// The dialog uses Nextcloud's global t() helper for translations; in unit
	// tests we just echo the source string.
	globalThis.t = (_app, text) => text
})

afterEach(() => {
	delete globalThis.t
})

const flushMicrotasks = async () => {
	// Two microtask flushes are enough to settle the dialog's async submit:
	// onSubmit() resolves once, then close()/error rendering happens.
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

describe('openInternalPublicPadDialog', () => {
	it('closes with the onSubmit result when submission succeeds', async () => {
		const onSubmit = vi.fn(async (name) => ({ name, ok: true }))

		const pending = openInternalPublicPadDialog({ onSubmit })

		const nameInput = document.body.querySelector('input[type="text"]')
		expect(nameInput).not.toBeNull()
		nameInput.value = 'My pad.pad'

		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		await expect(pending).resolves.toEqual({ name: 'My pad.pad', ok: true })
		expect(onSubmit).toHaveBeenCalledOnce()
	})

	it('keeps the dialog open and surfaces the onSubmit error inline', async () => {
		const onSubmit = vi.fn()
			.mockRejectedValueOnce(new Error('A file with this name already exists.'))
			.mockResolvedValueOnce({ ok: true })

		const pending = openInternalPublicPadDialog({ onSubmit })

		const button = document.body.querySelector('button.primary')
		button.click()
		await flushMicrotasks()

		expect(document.body.querySelector('p').textContent).toBe('A file with this name already exists.')
		expect(button.disabled).toBe(false)
		expect(button.textContent).toBe('Create')

		button.click()
		await flushMicrotasks()
		await expect(pending).resolves.toEqual({ ok: true })
		expect(onSubmit).toHaveBeenCalledTimes(2)
	})

	it('resolves with null when the user cancels via the close button', async () => {
		const onSubmit = vi.fn()
		const pending = openInternalPublicPadDialog({ onSubmit })

		document.body.querySelector('button[aria-label="Close"]').click()

		await expect(pending).resolves.toBeNull()
		expect(onSubmit).not.toHaveBeenCalled()
	})

	it('rejects empty input without calling onSubmit', async () => {
		const onSubmit = vi.fn()
		openInternalPublicPadDialog({ onSubmit })

		const nameInput = document.body.querySelector('input[type="text"]')
		nameInput.value = '  '

		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		expect(document.body.querySelector('p').textContent).toBe('File name is required.')
		expect(onSubmit).not.toHaveBeenCalled()
		document.body.querySelector('button[aria-label="Close"]').click()
	})

	it('ignores close while a submission is in flight', async () => {
		let resolveSubmit
		const onSubmit = vi.fn(() => new Promise((r) => { resolveSubmit = r }))

		const pending = openInternalPublicPadDialog({ onSubmit })
		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		// User impatiently clicks the close button mid-flight.
		document.body.querySelector('button[aria-label="Close"]').click()
		await flushMicrotasks()
		// Dialog must still be in the DOM.
		expect(document.body.querySelector('button.primary')).not.toBeNull()

		resolveSubmit({ ok: true })
		await flushMicrotasks()
		await expect(pending).resolves.toEqual({ ok: true })
	})
})

describe('openExternalPublicPadDialog', () => {
	it('closes with the onSubmit result when submission succeeds', async () => {
		const onSubmit = vi.fn(async (values) => ({ ...values, ok: true }))

		const pending = openExternalPublicPadDialog({ onSubmit })

		document.body.querySelector('input[type="url"]').value = 'https://pad.example.test/p/demo'
		document.body.querySelector('input[type="text"]').value = 'My external.pad'

		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		await expect(pending).resolves.toEqual({
			padUrl: 'https://pad.example.test/p/demo',
			name: 'My external.pad',
			ok: true,
		})
	})

	it('keeps the dialog open and surfaces the onSubmit error inline', async () => {
		const onSubmit = vi.fn()
			.mockRejectedValueOnce(new Error('A file with this name already exists.'))
			.mockResolvedValueOnce({ ok: true })

		const pending = openExternalPublicPadDialog({ onSubmit })

		document.body.querySelector('input[type="url"]').value = 'https://pad.example.test/p/demo'
		const nameInput = document.body.querySelector('input[type="text"]')
		nameInput.value = 'taken.pad'

		const button = document.body.querySelector('button.primary')
		button.click()
		await flushMicrotasks()

		expect(document.body.querySelector('p').textContent).toBe('A file with this name already exists.')
		expect(button.disabled).toBe(false)
		expect(button.textContent).toBe('Create')

		// User edits the name and retries.
		nameInput.value = 'free-name.pad'
		button.click()
		await flushMicrotasks()
		await expect(pending).resolves.toEqual({ ok: true })
		expect(onSubmit).toHaveBeenCalledTimes(2)
	})

	it('resolves with null when the user cancels via the close button', async () => {
		const onSubmit = vi.fn()
		const pending = openExternalPublicPadDialog({ onSubmit })

		document.body.querySelector('button[aria-label="Close"]').click()

		await expect(pending).resolves.toBeNull()
		expect(onSubmit).not.toHaveBeenCalled()
	})

	it('validates required URL and name before calling onSubmit', async () => {
		const onSubmit = vi.fn()
		openExternalPublicPadDialog({ onSubmit })

		document.body.querySelector('input[type="url"]').value = ''
		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		expect(document.body.querySelector('p').textContent).toBe('External pad URL is required.')
		expect(onSubmit).not.toHaveBeenCalled()

		document.body.querySelector('input[type="url"]').value = 'https://pad.example.test/p/demo'
		document.body.querySelector('input[type="text"]').value = '  '
		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		expect(document.body.querySelector('p').textContent).toBe('File name is required.')
		expect(onSubmit).not.toHaveBeenCalled()
		document.body.querySelector('button[aria-label="Close"]').click()
	})

	it('ignores close while a submission is in flight', async () => {
		let resolveSubmit
		const onSubmit = vi.fn(() => new Promise((r) => { resolveSubmit = r }))

		const pending = openExternalPublicPadDialog({ onSubmit })
		document.body.querySelector('input[type="url"]').value = 'https://pad.example.test/p/demo'
		document.body.querySelector('button.primary').click()
		await flushMicrotasks()

		document.body.querySelector('button[aria-label="Close"]').click()
		await flushMicrotasks()
		expect(document.body.querySelector('button.primary')).not.toBeNull()

		resolveSubmit({ ok: true })
		await flushMicrotasks()
		await expect(pending).resolves.toEqual({ ok: true })
	})
})
