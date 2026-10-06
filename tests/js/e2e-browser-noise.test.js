/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it, expect } from 'vitest'
import {
	KNOWN_ELSEWHERE,
	consoleIsOurs,
	errorIsOurs,
	matching,
	requestFailureIsOurs,
	responseIsOurs,
} from '../e2e/fixtures/browser-noise-rules.mjs'
import { summarise } from '../e2e/browser-noise-summary.mjs'

const specs = join(dirname(fileURLToPath(import.meta.url)), '../e2e/specs')

describe('the e2e specs', () => {
	/**
	 * A spec that takes `test` from Playwright itself runs without the
	 * guard, and nothing says so.
	 */
	it('take test and expect from the noise guard', () => {
		const files = readdirSync(specs).filter((name) => name.endsWith('.spec.ts'))
		expect(files.length).toBeGreaterThan(20)
		for (const name of files) {
			const source = readFileSync(join(specs, name), 'utf8')
			const fromPlaywright = [...source.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@playwright\/test'/g)]
				.flatMap((match) => match[1].split(',').map((part) => part.trim().split(/\s+as\s+/)[0]))
			expect(fromPlaywright, name).not.toContain('test')
			expect(fromPlaywright, name).not.toContain('expect')
			// Nor its default export, under whatever name.
			expect(source, name).not.toMatch(/import\s+[A-Za-z_$][\w$]*\s*(,\s*\{[^}]*\}\s*)?from\s*'@playwright\/test'/)
			// `test` itself from the fixture, not just anything.
			expect(source, name).toMatch(/import\s*\{[^}]*\btest\b[^}]*\}\s*from '\.\.\/fixtures\/browser-noise'/)
		}
	})
})

describe('what is this app\'s', () => {
	const app = 'https://nc.pad.test/custom_apps/etherpad_nextcloud/js/etherpad_nextcloud-viewer-main.mjs'
	const files = 'https://nc.pad.test/dist/files-init.js'

	it('goes by where a console line was logged', () => {
		expect(consoleIsOurs('TypeError: x is undefined', app)).toBe(true)
		// The Files app naming a pad's address is the Files app's.
		expect(consoleIsOurs('Could not open https://nc.pad.test/apps/etherpad_nextcloud/?file=/a.pad', files)).toBe(false)
		// Without a place, the text is all there is.
		expect(consoleIsOurs('failed in /custom_apps/etherpad_nextcloud/js/x.mjs', '')).toBe(true)
	})

	it('goes by the stack of an exception', () => {
		expect(errorIsOurs(`TypeError: boom\n    at mount (${app}:1:2)`)).toBe(true)
		expect(errorIsOurs(`Error: no pad at /apps/etherpad_nextcloud/p/1\n    at open (${files}:3:4)`)).toBe(false)
		expect(errorIsOurs('Error: boom in /custom_apps/etherpad_nextcloud/js/x.mjs')).toBe(true)
	})

	it('leaves a network failure on the way to the network', () => {
		const route = 'https://nc.pad.test/apps/etherpad_nextcloud/api/v1/pads/open-by-id'
		expect(requestFailureIsOurs('net::ERR_EMPTY_RESPONSE', route)).toBe(true)
		expect(requestFailureIsOurs('net::ERR_NETWORK_CHANGED', route)).toBe(false)
		expect(requestFailureIsOurs('net::ERR_CONNECTION_RESET', route)).toBe(false)
		expect(requestFailureIsOurs('net::ERR_EMPTY_RESPONSE', 'https://nc.pad.test/public.php/dav/files/x/')).toBe(false)
		expect(responseIsOurs(route)).toBe(true)
		expect(responseIsOurs('https://nc.pad.test/public.php/dav/files/admin/')).toBe(false)
	})
})

describe('the noise known elsewhere', () => {
	// Lines as the runs against Nextcloud 34 logged them.
	it.each([
		['viewer-registers-handlers-twice', '[ERROR] viewer: Could not register handler {app: viewer, uid: admin, level: 2, error: The handler is already registered, handler: Object} (https://nc.pad.test/apps/viewer/js/previewUtils-Dvsw19M-.chunk.mjs)'],
		['service-worker-stack-certificate', 'An SSL certificate error occurred when fetching the script.'],
		['service-worker-not-registered', '[ERROR] files: SW registration failed:  {app: files, level: 2, error: SecurityError: Failed to register a ServiceWorker: The provided scriptURL (\'https://nc.pad.test/ind…} (https://nc.pad.test/dist/core-common.js)'],
		['service-worker-public-csp', 'Creating a worker from \'https://nc.pad.test/index.php/apps/files/preview-service-worker.js\' violates the following Content Security Policy directive: "script-src \'nonce-abc=\'". (https://nc.pad.test/dist/files-init.js)'],
		['text-rich-workspace-tiptap', 'Error: [tiptap error]: The editor view is not available. Cannot access view[\'dom\'].\n    at Object.get (https://nc.pad.test/apps/text/js/Wrapper-COIqk83O.chunk.mjs:84:4314)'],
		['modal-focus-trap', 'Error: Your focus-trap must have at least one container with at least one tabbable node in it at all times\n    at w (https://nc.pad.test/dist/core-common.js:1:129877)'],
	])('%s', (id, text) => {
		expect(matching(KNOWN_ELSEWHERE, { kind: 'console', text })?.id).toBe(id)
	})

	it('knows each by its source, not by its words alone', () => {
		// The same tiptap words from another app's editor are not Text's
		// rich workspace.
		expect(matching(KNOWN_ELSEWHERE, { kind: 'console', text: 'Error: [tiptap error]: The editor view is not available.\n    at x (https://nc.pad.test/apps/other/js/a.mjs:1:1)' })).toBeUndefined()
		expect(matching(KNOWN_ELSEWHERE, { kind: 'pageerror', text: 'An SSL certificate error occurred when fetching the script.' })).toBeUndefined()
	})

	it('has one id per entry', () => {
		expect(new Set(KNOWN_ELSEWHERE.map((entry) => entry.id)).size).toBe(KNOWN_ELSEWHERE.length)
	})
})

describe('the summary', () => {
	const line = (entry) => JSON.stringify(entry) + '\n'

	it('lists what is not known, and counts the known per entry', () => {
		const summary = summarise(
			line({ test: 'a', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/admin/' })
			+ line({ test: 'b', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/admin/' })
			+ line({ test: 'a', known: 'modal-focus-trap' }),
		)
		expect(summary).toContain('Not known yet: 1 kind, 2 times, in 2 tests.')
		expect(summary).toContain('| 2 | 2 | response | `503 PROPFIND https://nc.pad.test/public.php/dav/files/admin/` |')
		expect(summary).toContain('<summary>Known and left out: 1 time</summary>')
		expect(summary).toContain('| 1 | 1 | `modal-focus-trap` |')
		// One that did not show up is there, at 0.
		expect(summary).toContain('| 0 | 0 | `viewer-registers-handlers-twice` |')
	})

	it('reads past a line a stopped worker cut short', () => {
		const summary = summarise(line({ test: 'a', known: 'modal-focus-trap' }) + '{"test":"b","kind":"cons')
		expect(summary).toContain('Nothing new.')
		expect(summary).toContain('1 line of the record could not be read.')
	})

	it('says when there is no record', () => {
		expect(summarise(null)).toContain('Nothing recorded: no test met any.')
	})
})
