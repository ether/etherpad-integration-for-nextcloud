/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { buildPadFrameSrcdoc } from '../../../src/lib/pad-frame-srcdoc.js'

describe('pad frame srcdoc builder', () => {
	it('embeds a normal Etherpad URL', () => {
		const srcdoc = buildPadFrameSrcdoc('https://pad.example.test/p/demo')

		expect(srcdoc).toContain('<iframe src="https://pad.example.test/p/demo" title="Etherpad"></iframe>')
	})

	it('allows http URLs for local or explicitly configured non-TLS pads', () => {
		const srcdoc = buildPadFrameSrcdoc('http://localhost:9001/p/demo')

		expect(srcdoc).toContain('src="http://localhost:9001/p/demo"')
	})

	it('escapes ampersands in query strings', () => {
		const srcdoc = buildPadFrameSrcdoc('https://pad.example.test/p/demo?foo=1&bar=2')

		expect(srcdoc).toContain('src="https://pad.example.test/p/demo?foo=1&amp;bar=2"')
	})

	it('escapes quotes in the iframe src attribute', () => {
		const srcdoc = buildPadFrameSrcdoc('https://pad.example.test/p/"demo"')

		expect(srcdoc).toContain('src="https://pad.example.test/p/&quot;demo&quot;"')
	})

	it('escapes angle brackets instead of allowing tag injection', () => {
		const srcdoc = buildPadFrameSrcdoc('https://pad.example.test/p/<script>alert(1)</script>')

		expect(srcdoc).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
		expect(srcdoc).not.toContain('<script>alert(1)</script>')
	})

	it('handles empty and nullish URLs as an empty iframe src', () => {
		expect(buildPadFrameSrcdoc('')).toContain('src=""')
		expect(buildPadFrameSrcdoc(null)).toContain('src=""')
		expect(buildPadFrameSrcdoc(undefined)).toContain('src=""')
	})

	it('drops unsafe iframe URL schemes', () => {
		expect(buildPadFrameSrcdoc('javascript:alert(1)')).toContain('src=""')
		expect(buildPadFrameSrcdoc('data:text/html,<script>alert(1)</script>')).toContain('src=""')
	})

	it('adds a restrictive CSP for the wrapper document', () => {
		const srcdoc = buildPadFrameSrcdoc('https://pad.example.test/p/demo')

		expect(srcdoc).toContain('http-equiv="Content-Security-Policy"')
		expect(srcdoc).toContain("default-src 'none'; frame-src http: https:; style-src 'unsafe-inline'")
	})
})
