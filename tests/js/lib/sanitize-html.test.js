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
})
