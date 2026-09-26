/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { handFocusTo } from '../../../src/lib/hand-focus.js'

const card = (html) => {
	const node = document.createElement('div')
	node.innerHTML = html
	document.body.appendChild(node)
	return node
}

afterEach(() => {
	document.body.innerHTML = ''
	vi.restoreAllMocks()
})

describe('handFocusTo', () => {
	it('gives the focus to the first action', () => {
		const scope = card('<p>Boom</p><a href="/o">Open the original</a><button>Create</button>')

		handFocusTo(scope, scope.querySelector('p'))

		expect(document.activeElement).toBe(scope.querySelector('a'))
	})

	it('gives it to the message when there is no action, which a tabindex makes focusable', () => {
		const scope = card('<p>Boom</p>')
		const message = scope.querySelector('p')

		handFocusTo(scope, message)

		expect(document.activeElement).toBe(message)
		expect(message.getAttribute('tabindex')).toBe('-1')
	})

	it('keeps the page from scrolling only when asked to', () => {
		const scope = card('<button>Try again</button>')
		const focus = vi.spyOn(HTMLElement.prototype, 'focus')

		handFocusTo(scope, null)
		handFocusTo(scope, null, { preventScroll: true })

		expect(focus.mock.calls).toEqual([[{ preventScroll: false }], [{ preventScroll: true }]])
	})

	it('leaves the focus alone with nothing to give it to', () => {
		handFocusTo(null, null)

		expect(document.activeElement).toBe(document.body)
	})
})
