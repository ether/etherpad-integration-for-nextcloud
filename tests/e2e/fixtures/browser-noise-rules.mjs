/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Which browser noise is this app's, and which noise of other software is
 * known: the rules fixtures/browser-noise.ts applies, in plain JavaScript,
 * so that browser-noise-summary.mjs and the unit tests read the same.
 */

/**
 * Where the run writes its results: playwright.config.ts's `outputDir`,
 * and where the summary looks for the record by default.
 */
export const OUTPUT_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../../test-results')

/**
 * The run's record of other software's noise, one JSON object a line.
 *
 * @param {string} outputDir
 */
export const recordIn = (outputDir) => join(outputDir, 'browser-noise.jsonl')

/**
 * This app's scripts and routes: an app directory named for it, under
 * apps/, custom_apps/ or another apps path. Not a path that merely
 * carries its id further in, such as the theming app serving its icon
 * from /apps/theming/img/etherpad_nextcloud/.
 */
const OURS = /\/[\w-]*apps[\w-]*\/etherpad_nextcloud\//

/**
 * Documents this app makes without an address of its own: the viewer's
 * srcdoc wrapper around the Etherpad frame.
 */
const OUR_DOCUMENTS = new Set(['about:srcdoc'])

/**
 * The `at` lines of a stack, the places an error went through.
 *
 * @param {string} text
 */
const framesOf = (text) => text.split('\n').filter((line) => /^\s+at /.test(line)).join('\n')

/**
 * The script a stack was thrown from: the URL in its first frame, or ''.
 *
 * @param {string} stack
 */
export const sourceOf = (stack) => /\((https?:\/\/[^\s)]+?)(?::\d+:\d+)?\)|at (https?:\/\/[^\s)]+?)(?::\d+:\d+)?$/m.exec(framesOf(stack))?.slice(1).find(Boolean) ?? ''

/**
 * A console line is this app's when this app's script or document logged
 * it, or when the error it logs was thrown through this app's scripts:
 * the Viewer's Vue logs what this app's component throws from its own
 * bundle. Not by the words in it otherwise - a line of the Files app that
 * names a pad's address is the Files app's - save when the browser gives
 * neither a place nor a stack.
 *
 * The browser's refusal to frame another origin is this app's too: it is
 * the one that frames Etherpad on these pages, and the policy refusing it
 * is partly its own.
 *
 * @param {string} text
 * @param {string} where the URL the browser gives as the line's location, or ''
 */
export const consoleIsOurs = (text, where) => {
	if (OURS.test(where) || OUR_DOCUMENTS.has(where) || text.startsWith('Refused to frame ')) {
		return true
	}
	const frames = framesOf(text)
	if (frames !== '') {
		return OURS.test(frames)
	}
	return where === '' && OURS.test(text)
}

/**
 * An exception is this app's when its stack runs through this app's
 * scripts. Its message only when there is no stack to go by.
 *
 * @param {string} stack
 */
export const errorIsOurs = (stack) => {
	const frames = framesOf(stack)
	return OURS.test(frames !== '' ? frames : stack)
}

/**
 * Failures on the way rather than answers: the network changed or a
 * connection dropped. The Playwright config retries for them; a request
 * of this app's that meets one is noise, not an error of the app.
 */
const TRANSIENT_FAILURES = new Set([
	'net::ERR_NETWORK_CHANGED',
	'net::ERR_CONNECTION_RESET',
	'net::ERR_CONNECTION_CLOSED',
	'net::ERR_INTERNET_DISCONNECTED',
])

/**
 * How long a request of this app's ran before the client gave up on it:
 * src/lib/fetch-helpers.js aborts after ten seconds, which Chrome reports
 * like any page that leaves, `net::ERR_ABORTED`. Some slack below that.
 */
export const CLIENT_TIMEOUT_MS = 9_500

/**
 * A request that failed outright is this app's when it went to one of its
 * routes and failed for another reason than a dropped connection, or was
 * aborted after hanging for as long as the client waits. An abort sooner
 * is a page leaving, and that is nobody's error.
 *
 * @param {string} failure Chrome's error text
 * @param {string} url
 * @param {number} elapsedMs how long the request ran
 */
export const requestFailureIsOurs = (failure, url, elapsedMs) => {
	if (!OURS.test(url) || TRANSIENT_FAILURES.has(failure)) {
		return false
	}
	return failure !== 'net::ERR_ABORTED' || elapsedMs >= CLIENT_TIMEOUT_MS
}

/**
 * A server error is this app's when one of its routes answered it and it
 * is not an answer the client is built for: a 502, 503 or 504 that says
 * `retryable` is the app saying "later" (a file locked for a moment, its
 * Etherpad not reachable), and one that is not the app's JSON at all came
 * from a gateway in front of it - src/lib/fetch-helpers.js reads both so.
 *
 * @param {string} url
 * @param {number} status
 * @param {string} body the answer's body, or '' when it could not be read
 */
export const serverErrorIsOurs = (url, status, body) => {
	if (!OURS.test(url)) {
		return false
	}
	if (![502, 503, 504].includes(status)) {
		return true
	}
	let answer
	try {
		answer = JSON.parse(body)
	} catch {
		return false
	}
	return !(answer !== null && typeof answer === 'object' && answer.retryable === true)
}

/** @param {string} url */
export const responseIsOurs = (url) => OURS.test(url)

/**
 * @typedef {'console' | 'pageerror' | 'requestfailed' | 'response'} NoiseKind
 * @typedef {{ kind: NoiseKind, text: string }} Noise
 * @typedef {{ kind: NoiseKind, pattern: RegExp, reason: string }} Allowance
 */

/**
 * Noise of other software, seen in this suite's runs, and why it is not
 * this app's. Left out of the summary's table and counted per entry there,
 * so one that stops showing up can be struck. Not this app's errors:
 * those fail whatever this list says. A console line's text ends in its
 * location, ` (<url>)`, when the browser gives one.
 *
 * @type {ReadonlyArray<Allowance & { id: string }>}
 */
export const KNOWN_ELSEWHERE = [
	{
		id: 'viewer-registers-handlers-twice',
		kind: 'console',
		pattern: /^\[ERROR\] viewer: Could not register handler \{[^}]*error: The handler is already registered/,
		reason: 'The Viewer registers every handler in `_oca_viewer_handlers` twice: viewer-init when it starts, viewer-main again on DOMContentLoaded. The line names the handler, this app\'s among them; it is registered once all the same.',
	},
	{
		id: 'service-worker-stack-certificate',
		kind: 'console',
		pattern: /^An SSL certificate error occurred when fetching the script\.(?: \([^)]*\))?$/,
		reason: 'The Files app\'s preview service worker, in the Docker stack: Chrome does not register a service worker from an origin whose certificate it does not trust, and the stack signs itself.',
	},
	{
		id: 'service-worker-not-registered',
		kind: 'console',
		pattern: /^\[ERROR\] files: SW registration failed: /,
		reason: 'The Files app\'s preview service worker not registering: refused for the stack\'s own certificate, and on a public share page by Nextcloud\'s CSP, which sets no `worker-src` there.',
	},
	{
		id: 'service-worker-public-csp',
		kind: 'console',
		pattern: /^Creating a worker from '[^']*\/apps\/files\/preview-service-worker\.js' violates the following Content Security Policy directive/,
		reason: 'The same worker on a public share page: Nextcloud\'s CSP there sets no `worker-src`, so `script-src` decides.',
	},
	{
		id: 'text-rich-workspace-tiptap',
		kind: 'console',
		pattern: /^Error: \[tiptap error\]: The editor view is not available\.[\s\S]*\/apps\/text\//,
		reason: 'Text\'s rich workspace, the Readme.md above the file list, taken down before its editor was up.',
	},
	{
		id: 'modal-focus-trap',
		kind: 'console',
		pattern: /^Error: Your focus-trap must have at least one container[\s\S]*\/dist\/core-common\.js/,
		reason: 'A Nextcloud modal\'s focus trap, now and then, in Nextcloud\'s own bundle.',
	},
]

/**
 * The first entry of `list` the noise matches, or undefined: one rule for
 * what a test allows and what is known elsewhere. By `search`, which
 * starts from the beginning whatever flags a pattern carries: `test` on a
 * `g` or `y` pattern goes on from where the last match ended.
 *
 * @template {Allowance} T
 * @param {ReadonlyArray<T>} list
 * @param {Noise} noise
 * @returns {T | undefined}
 */
export const matching = (list, noise) => list.find((entry) => entry.kind === noise.kind && noise.text.search(entry.pattern) !== -1)
