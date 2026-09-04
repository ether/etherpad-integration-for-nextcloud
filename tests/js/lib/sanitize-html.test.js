// @vitest-environment jsdom
// DOMPurify is tested against jsdom upstream; happy-dom mis-sanitizes, so this
// file pins jsdom to exercise the real sanitizer behavior.
import { describe, expect, it } from 'vitest'
import { sanitizeSnapshotHtml } from '../../../src/lib/sanitize-html.js'

describe('sanitizeSnapshotHtml', () => {
	it('keeps the allowed formatting tags', () => {
		const html = '<p>Hello <strong>bold</strong> <em>italic</em></p><ul><li>one</li></ul>'
		expect(sanitizeSnapshotHtml(html)).toBe(html)
	})

	it('strips <script> tags entirely', () => {
		const out = sanitizeSnapshotHtml('<p>safe</p><script>alert(1)</script>')
		expect(out).toBe('<p>safe</p>')
		expect(out).not.toContain('script')
	})

	it('removes event-handler attributes while keeping the element text', () => {
		const out = sanitizeSnapshotHtml('<p onclick="alert(1)">click</p>')
		expect(out).toBe('<p>click</p>')
		expect(out).not.toContain('onclick')
	})

	it('drops an <img onerror> XSS vector (img is not allowed)', () => {
		const out = sanitizeSnapshotHtml('<img src=x onerror="alert(1)">')
		expect(out).not.toContain('onerror')
		expect(out).not.toContain('<img')
	})

	it('strips all attributes, including class', () => {
		const out = sanitizeSnapshotHtml('<p class="evil" style="color:red">x</p>')
		expect(out).toBe('<p>x</p>')
	})

	it('unwraps disallowed tags but keeps their text content', () => {
		const out = sanitizeSnapshotHtml('<div><a href="javascript:alert(1)">link</a></div>')
		expect(out).not.toContain('<a')
		expect(out).not.toContain('javascript:')
		expect(out).toContain('link')
	})

	it('tolerates non-string / nullish input', () => {
		expect(sanitizeSnapshotHtml(null)).toBe('')
		expect(sanitizeSnapshotHtml(undefined)).toBe('')
	})

	/**
	 * The browser stage stands on its own: even a link that arrives
	 * without them cannot hand the opened tab a reference back to this
	 * one.
	 */
	it('keeps safe links and forces target and rel itself', () => {
		const out = sanitizeSnapshotHtml('<a href="https://example.test/x">go</a>')

		expect(out).toContain('href="https://example.test/x"')
		expect(out).toContain('target="_blank"')
		expect(out).toContain('rel="noopener noreferrer"')
	})

	it('keeps mailto links', () => {
		expect(sanitizeSnapshotHtml('<a href="mailto:a@example.test">write</a>')).toContain('mailto:a@example.test')
	})

	it.each([
		['javascript:alert(1)'],
		['JaVaScRiPt:alert(1)'],
		['data:text/html,<script>alert(1)</script>'],
		['vbscript:msgbox(1)'],
		['//evil.test/'],
	])('drops the href for %s while keeping the text', (href) => {
		const out = sanitizeSnapshotHtml(`<a href="${href}">click</a>`)

		expect(out).toContain('click')
		expect(out).not.toContain('href')
	})

	it('still allows no attribute other than a link\'s', () => {
		const out = sanitizeSnapshotHtml('<p class="x" style="color:red" onclick="alert(1)">text</p>')

		expect(out).toBe('<p>text</p>')
	})
})
