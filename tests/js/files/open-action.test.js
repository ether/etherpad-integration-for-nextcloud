/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { extractFileIdFromActionContext } from '../../../src/files/open-action.js'

describe('open action file id extraction', () => {
	it('reads common direct context fields', () => {
		expect(extractFileIdFromActionContext({ fileId: 42 })).toBe(42)
		expect(extractFileIdFromActionContext({ file_id: '43' })).toBe(43)
		expect(extractFileIdFromActionContext({ nodeId: 44 })).toBe(44)
	})

	it('reads file ids from model getter APIs', () => {
		const model = {
			get: (key) => key === 'fileid' ? '45' : null,
		}

		expect(extractFileIdFromActionContext({ model })).toBe(45)
	})

	it('reads file ids from DOM dataset attributes', () => {
		const element = document.createElement('div')
		element.setAttribute('data-file-id', '46')

		expect(extractFileIdFromActionContext({ element })).toBe(46)
	})

	it('reads file ids from files hrefs', () => {
		const element = document.createElement('div')
		const link = document.createElement('a')
		link.setAttribute('href', '/index.php/apps/files/files/47?dir=/Docs')
		element.appendChild(link)

		expect(extractFileIdFromActionContext({ element })).toBe(47)
	})

	it('ignores invalid or missing context data', () => {
		expect(extractFileIdFromActionContext(null)).toBeNull()
		expect(extractFileIdFromActionContext({ fileId: 0 })).toBeNull()
		expect(extractFileIdFromActionContext({ fileId: 'abc' })).toBeNull()
	})
})
