/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it, expect } from 'vitest'
import {
	CLIENT_TIMEOUT_MS,
	KNOWN_ELSEWHERE,
	consoleIsOurs,
	errorIsOurs,
	matching,
	requestFailureIsOurs,
	responseIsOurs,
	serverErrorIsOurs,
	sourceOf,
} from '../e2e/fixtures/browser-noise-rules.mjs'
import { summarise } from '../e2e/browser-noise-summary.mjs'

const specs = join(dirname(fileURLToPath(import.meta.url)), '../e2e/specs')

/** Every spec Playwright runs, in subfolders too: its testMatch is `specs/.*\.spec\.ts`. */
const specFiles = () => readdirSync(specs, { recursive: true })
	.map(String)
	.filter((name) => name.endsWith('.spec.ts'))
	.map((name) => [name, readFileSync(join(specs, name), 'utf8')])

/**
 * What a bracket holds: from just after it opens to where it closes, past
 * the brackets of the same kind inside it.
 *
 * @param {string} source
 * @param {number} start just after the opening bracket
 * @param {string} open
 * @param {string} close
 */
const enclosedAt = (source, start, open = '(', close = ')') => {
	let depth = 1
	for (let i = start; i < source.length; i++) {
		if (source[i] === open) {
			depth++
		} else if (source[i] === close && --depth === 0) {
			return source.slice(start, i).trim()
		}
	}
	return source.slice(start).trim()
}

/**
 * Without comments: a comment that names a storageState sets none. A `//`
 * after a colon is an address, not a comment.
 *
 * @param {string} text
 */
const withoutComments = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1')

const STATE_KEY = /\bstorageState\s*:/

/**
 * Whether options name a storageState: in themselves, or in a constant
 * they are, or spread.
 *
 * @param {string} given what the call is given
 * @param {string} source the spec, for its constants
 */
const namesAState = (given, source) => {
	const options = withoutComments(given)
	if (STATE_KEY.test(options)) {
		return true
	}
	return [...options.matchAll(/(?:^|\.\.\.)\s*([A-Z_][A-Z0-9_]*)\b/g)].some(([, constant]) => {
		const declared = new RegExp(`const ${constant}\\s*=\\s*\\{`).exec(source)
		return declared !== null
			&& STATE_KEY.test(withoutComments(enclosedAt(source, declared.index + declared[0].length, '{', '}')))
	})
}

describe('the e2e specs', () => {
	/**
	 * A spec that takes `test` from Playwright itself runs without the
	 * guard, and nothing says so.
	 */
	it('take test and expect from the noise guard', () => {
		const files = specFiles()
		expect(files.length).toBeGreaterThan(20)
		for (const [name, source] of files) {
			const fromPlaywright = [...source.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@playwright\/test'/g)]
				.flatMap((match) => match[1].split(',').map((part) => part.trim().split(/\s+as\s+/)[0]))
			expect(fromPlaywright, name).not.toContain('test')
			expect(fromPlaywright, name).not.toContain('expect')
			// Nor its default export, under whatever name.
			expect(source, name).not.toMatch(/import\s+[A-Za-z_$][\w$]*\s*(,\s*\{[^}]*\}\s*)?from\s*'@playwright\/test'/)
			// `test` itself from the fixture, not just anything.
			expect(source, name).toMatch(/import\s*\{[^}]*\btest\b[^}]*\}\s*from '(\.\.\/)+fixtures\/browser-noise'/)
		}
	})

	/**
	 * A context a test opens itself is watched only once it is handed to
	 * the guard; one it forgets would go unseen.
	 */
	it('hand every browser context they open to the guard', () => {
		for (const [name, source] of specFiles()) {
			const opened = [...source.matchAll(/browser\.newContext\(/g)].length
			const named = [...source.matchAll(/const (\w+) = await browser\.newContext\(/g)].map((match) => match[1])
			expect(named.length, `${name}: a context opened without a name to hand over`).toBe(opened)
			for (const context of named) {
				expect(source, `${name}: ${context}`).toMatch(new RegExp(`const ${context} = await browser\\.newContext\\([^\\n]*\\)\\n\\s*browserNoise\\.watch\\(${context}\\)`))
			}
		}
	})

	/**
	 * A context opened without a storageState is signed in as the test
	 * user, whom the project's state fills in: a visitor meant to be
	 * signed out would not be, and a public page would answer as it does
	 * for its owner. Each context says which it is, a state file or none;
	 * so does a page the browser opens in a context of its own.
	 */
	it('say how every context they open is signed in', () => {
		for (const [name, source] of specFiles()) {
			for (const match of source.matchAll(/\.newContext\s*\(|\bbrowser\.newPage\s*\(/g)) {
				// Not one a comment names.
				if (/^\s*(\/\/|\*)/.test(source.slice(source.lastIndexOf('\n', match.index) + 1, match.index))) {
					continue
				}
				const given = enclosedAt(source, match.index + match[0].length)
				expect(namesAState(given, source), `${name}: ${match[0]}${given})`).toBe(true)
			}
		}
	})
})

describe('what is this app\'s', () => {
	const app = 'https://nc.pad.test/custom_apps/etherpad_nextcloud/js/etherpad_nextcloud-viewer-main.mjs'
	const viewer = 'https://nc.pad.test/apps/viewer/js/viewer-main.mjs'
	const files = 'https://nc.pad.test/dist/files-init.js'

	it('goes by where a console line was logged, and what it went through', () => {
		expect(consoleIsOurs('TypeError: x is undefined', app)).toBe(true)
		// The Viewer's Vue logs what this app's component throws.
		expect(consoleIsOurs(`TypeError: x is undefined\n    at renderContentView (${app}:1:2)\n    at render (${viewer}:3:4)`, viewer)).toBe(true)
		// The Files app naming a pad's address is the Files app's.
		expect(consoleIsOurs('Could not open https://nc.pad.test/apps/etherpad_nextcloud/?file=/a.pad', files)).toBe(false)
		// Without a place or a stack, the text is all there is.
		expect(consoleIsOurs('failed in /custom_apps/etherpad_nextcloud/js/x.mjs', '')).toBe(true)
	})

	it('counts the documents it makes, and the frames it is refused', () => {
		expect(consoleIsOurs('Refused to load the script \'x\'', 'about:srcdoc')).toBe(true)
		expect(consoleIsOurs('Refused to frame \'https://ep.pad.test/\' because it violates the following Content Security Policy directive: "frame-src \'self\'".', 'https://nc.pad.test/index.php/apps/files/')).toBe(true)
	})

	it('goes by the stack of an exception', () => {
		expect(errorIsOurs(`TypeError: boom\n    at mount (${app}:1:2)`)).toBe(true)
		expect(errorIsOurs(`Error: no pad at /apps/etherpad_nextcloud/p/1\n    at open (${files}:3:4)`)).toBe(false)
		expect(errorIsOurs('Error: boom in /custom_apps/etherpad_nextcloud/js/x.mjs')).toBe(true)
		expect(sourceOf(`TypeError: boom\n    at mount (${app}:1:2)\n    at x (${files}:3:4)`)).toBe(app)
		expect(sourceOf(`TypeError: boom\n    at ${files}:3:4`)).toBe(files)
		expect(sourceOf('Error: no stack')).toBe('')
	})

	it('is its routes, not another app\'s path that carries its id', () => {
		expect(responseIsOurs('https://nc.pad.test/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id')).toBe(true)
		expect(responseIsOurs('https://nc.pad.test/apps/etherpad_nextcloud/js/x.mjs')).toBe(true)
		expect(responseIsOurs('https://nc.pad.test/ocs/v2.php/apps/etherpad_nextcloud/api/v1/x')).toBe(true)
		expect(responseIsOurs('https://nc.pad.test/index.php/apps/theming/img/etherpad_nextcloud/app-dark.svg')).toBe(false)
		expect(responseIsOurs('https://nc.pad.test/public.php/dav/files/admin/')).toBe(false)
	})

	it('reads a server error by what the client makes of it', () => {
		const route = 'https://nc.pad.test/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id'
		expect(serverErrorIsOurs(route, 500, '{"message":"Request failed."}')).toBe(true)
		// The app saying "later": a file locked, its Etherpad not reachable.
		expect(serverErrorIsOurs(route, 503, '{"message":"locked","retryable":true}')).toBe(false)
		// A gateway's page in front of the app.
		expect(serverErrorIsOurs(route, 502, '<html>Bad Gateway</html>')).toBe(false)
		// The app's own 503 always says retryable; one that does not is a fault.
		expect(serverErrorIsOurs(route, 503, '{"message":"x"}')).toBe(true)
		expect(serverErrorIsOurs('https://nc.pad.test/public.php/dav/files/admin/', 500, '')).toBe(false)
	})

	it('leaves a network failure on the way, and a page leaving, to the network', () => {
		const route = 'https://nc.pad.test/apps/etherpad_nextcloud/api/v1/pads/open-by-id'
		expect(requestFailureIsOurs('net::ERR_EMPTY_RESPONSE', route, 50)).toBe(true)
		expect(requestFailureIsOurs('net::ERR_NETWORK_CHANGED', route, 50)).toBe(false)
		expect(requestFailureIsOurs('net::ERR_CONNECTION_RESET', route, 50)).toBe(false)
		expect(requestFailureIsOurs('net::ERR_EMPTY_RESPONSE', 'https://nc.pad.test/public.php/dav/files/x/', 50)).toBe(false)
		// Aborted as a page left, or because the client stopped waiting.
		expect(requestFailureIsOurs('net::ERR_ABORTED', route, 300)).toBe(false)
		expect(requestFailureIsOurs('net::ERR_ABORTED', route, CLIENT_TIMEOUT_MS + 100)).toBe(true)
	})
})

describe('the noise known elsewhere', () => {
	// Lines as the runs against Nextcloud 32 and 34 logged them.
	it.each([
		['viewer-registers-handlers-twice', '[ERROR] viewer: Could not register handler {app: viewer, uid: admin, level: 2, error: The handler is already registered, handler: Object} (https://nc.pad.test/apps/viewer/js/previewUtils-Dvsw19M-.chunk.mjs)'],
		['service-worker-stack-certificate', 'An SSL certificate error occurred when fetching the script.'],
		['service-worker-stack-certificate', 'An SSL certificate error occurred when fetching the script. (https://nc.pad.test/index.php/apps/files/preview-service-worker.js)'],
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

	it('matches every time, whatever flags a pattern carries', () => {
		const allowed = [{ kind: 'response', pattern: /open-by-id/g, reason: 'on purpose' }]
		const noise = { kind: 'response', text: '503 POST https://nc.pad.test/index.php/apps/etherpad_nextcloud/api/v1/pads/open-by-id' }
		expect([1, 2, 3, 4].map(() => matching(allowed, noise) !== undefined)).toEqual([true, true, true, true])
	})
})

describe('the summary', () => {
	const line = (entry) => JSON.stringify(entry) + '\n'

	it('lists what is not known, and counts the known per entry', () => {
		const summary = summarise(
			line({ test: 'a', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/admin/', where: '' })
			+ line({ test: 'b', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/admin/', where: '' })
			+ line({ test: 'a', known: 'modal-focus-trap' }),
		)
		expect(summary).toContain('Not known yet: 1 kind, 2 times, in 2 tests.')
		expect(summary).toContain('| 2 | 2 | response | `503 PROPFIND https://nc.pad.test/public.php/dav/files/…/` |')
		expect(summary).toContain('<summary>Known and left out: 1 time</summary>')
		expect(summary).toContain('| 1 | 1 | `modal-focus-trap` |')
		// One that did not show up is there, at 0.
		expect(summary).toContain('| 0 | 0 | `viewer-registers-handlers-twice` |')
	})

	it('folds what changes from one test to the next', () => {
		const summary = summarise(
			line({ test: 'a', kind: 'response', text: '503 GET https://nc.pad.test/core/preview?fileId=101&x=32', where: '' })
			+ line({ test: 'b', kind: 'response', text: '503 GET https://nc.pad.test/core/preview?fileId=202&x=32', where: '' })
			+ line({ test: 'c', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/AbCdEfGhIjKlMnO/', where: '' })
			+ line({ test: 'd', kind: 'response', text: '503 PROPFIND https://nc.pad.test/public.php/dav/files/ZyXwVuTsRqPoNmL/', where: '' }),
		)
		expect(summary).toContain('Not known yet: 2 kinds, 4 times, in 4 tests.')
	})

	it('keeps the same words from two sources apart', () => {
		const summary = summarise(
			line({ test: 'a', kind: 'pageerror', text: 'Error: boom\n    at x (https://nc.pad.test/apps/a/x.js:1:1)', where: 'https://nc.pad.test/apps/a/x.js' })
			+ line({ test: 'b', kind: 'pageerror', text: 'Error: boom\n    at y (https://nc.pad.test/apps/b/y.js:1:1)', where: 'https://nc.pad.test/apps/b/y.js' }),
		)
		expect(summary).toContain('Not known yet: 2 kinds')
		expect(summary).toContain('`Error: boom (https://nc.pad.test/apps/a/x.js)`')
	})

	it('reads past a line a stopped worker cut short', () => {
		const summary = summarise(line({ test: 'a', known: 'modal-focus-trap' }) + '{"test":"b","kind":"cons')
		expect(summary).toContain('Nothing new.')
		expect(summary).toContain('1 line of the record could not be read.')
	})

	it('tells a run that met no noise from one that ran no test', () => {
		expect(summarise('')).toContain('Nothing recorded: no test met any.')
		expect(summarise(null)).toContain('No record: the suite ran no test')
	})
})
