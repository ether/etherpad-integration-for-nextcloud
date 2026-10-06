/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Which browser noise is this app's, and which noise of other software is
 * known: the rules fixtures/browser-noise.ts applies, in plain JavaScript,
 * so that browser-noise-summary.mjs and the unit tests read the same.
 */

/** The run's noise of other software, one JSON object a line, in the output directory. */
export const NOISE_FILE = 'browser-noise.jsonl'

/** This app's scripts and routes, installed under apps/ or custom_apps/. */
const OURS = /\/etherpad_nextcloud\//

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
 * A console line is this app's when this app's script or page logged it.
 * By where it was logged, not by what it says: a line of the Files app
 * that names a pad's address is the Files app's. Its text only when the
 * browser gives no place.
 *
 * @param {string} text
 * @param {string} where the URL the browser gives as the line's location, or ''
 */
export const consoleIsOurs = (text, where) => OURS.test(where !== '' ? where : text)

/**
 * An exception is this app's when its stack runs through this app's
 * scripts. Its message only when there is no stack to go by.
 *
 * @param {string} stack
 */
export const errorIsOurs = (stack) => {
	const frames = stack.split('\n').filter((line) => /^\s+at /.test(line))
	return OURS.test(frames.length > 0 ? frames.join('\n') : stack)
}

/**
 * @param {string} failure Chrome's error text
 * @param {string} url
 */
export const requestFailureIsOurs = (failure, url) => !TRANSIENT_FAILURES.has(failure) && OURS.test(url)

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
 * those fail whatever this list says.
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
		pattern: /^An SSL certificate error occurred when fetching the script\.$/,
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
 * what a test allows and what is known elsewhere.
 *
 * @template {Allowance} T
 * @param {ReadonlyArray<T>} list
 * @param {Noise} noise
 * @returns {T | undefined}
 */
export const matching = (list, noise) => list.find((entry) => entry.kind === noise.kind && entry.pattern.test(noise.text))
