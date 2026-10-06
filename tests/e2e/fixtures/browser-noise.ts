/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { appendFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { test as base, expect, type BrowserContext, type Response } from '@playwright/test'

/**
 * What the browser reported during a test that no assertion looked at: an
 * error on the console, an exception nothing caught, a request that failed
 * outright, a server error. A flow can pass every assertion and still
 * leave one of these behind - a script that threw after the button showed
 * up, a request the page fired and forgot. The specs import `test` from
 * here.
 *
 * A test fails on what comes from this app: its scripts, its routes and
 * their answers. The app runs inside Nextcloud next to other apps, whose
 * noise is not this app's to fix, and which would turn runs red with every
 * Nextcloud release. On Nextcloud 34 alone the Files app's service worker,
 * the Viewer registering each handler twice, Text's rich workspace and a
 * modal's focus trap log errors on pages this app hardly touches. Those,
 * known and explained, are listed below and left out. Anything else goes
 * into the test's report instead, as `browser-noise`, where it can be read
 * without failing anyone, and into one file for the whole run
 * (`browser-noise.jsonl` in the output directory), which
 * `browser-noise-summary.mjs` groups for the CI run's summary: so the noise
 * a new release brings stands out, and the known does not repeat itself.
 *
 * What a test causes on purpose it allows itself, through
 * `browserNoise.allow()`.
 */
export type NoiseKind = 'console' | 'pageerror' | 'requestfailed' | 'response'

type Noise = { kind: NoiseKind, text: string, ours: boolean }

const isKnownElsewhere = (noise: Noise): boolean =>
	KNOWN_ELSEWHERE.some((known) => known.kind === noise.kind && known.pattern.test(noise.text))

type Allowance = { kind: NoiseKind, pattern: RegExp, reason: string }

/**
 * Noise of other software, seen in this suite's runs, and why it is not
 * this app's. Left out of the summary but counted there, so one that
 * stops showing up can be struck. Not this app's errors: those fail
 * whatever this list says.
 */
const KNOWN_ELSEWHERE: readonly Allowance[] = [
	{
		kind: 'console',
		pattern: /^\[ERROR\] viewer: Could not register handler \{[^}]*error: The handler is already registered/,
		reason: 'The Viewer registers every handler in `_oca_viewer_handlers` twice: viewer-init when it starts, viewer-main again on DOMContentLoaded. The line names the handler, this app\'s among them; it is registered once all the same.',
	},
	{
		kind: 'console',
		pattern: /^An SSL certificate error occurred when fetching the script\.$/,
		reason: 'The Files app\'s preview service worker, in the Docker stack: Chrome does not register a service worker from an origin whose certificate it does not trust, and the stack signs itself.',
	},
	{
		kind: 'console',
		pattern: /^\[ERROR\] files: SW registration failed: /,
		reason: 'The Files app\'s preview service worker not registering: refused for the stack\'s own certificate, and on a public share page by Nextcloud\'s CSP, which sets no `worker-src` there.',
	},
	{
		kind: 'console',
		pattern: /^Creating a worker from '[^']*\/apps\/files\/preview-service-worker\.js' violates the following Content Security Policy directive/,
		reason: 'The same worker on a public share page: Nextcloud\'s CSP there sets no `worker-src`, so `script-src` decides.',
	},
	{
		kind: 'console',
		pattern: /^Error: \[tiptap error\]: The editor view is not available\.[\s\S]*\/apps\/text\//,
		reason: 'Text\'s rich workspace, the Readme.md above the file list, taken down before its editor was up.',
	},
	{
		kind: 'console',
		pattern: /^Error: Your focus-trap must have at least one container[\s\S]*\/dist\/core-common\.js/,
		reason: 'A Nextcloud modal\'s focus trap, now and then, in Nextcloud\'s own bundle.',
	},
]

/** The run's noise of other software, one JSON object a line, in the output directory. */
export const NOISE_FILE = 'browser-noise.jsonl'

/** This app's scripts and routes, installed under apps/ or custom_apps/. */
const OURS = /\/etherpad_nextcloud\//

/**
 * A static file the page cannot load is a broken page, whatever the
 * status. An API call answered with 4xx is an answer, and the specs check
 * those themselves.
 */
const STATIC_FILES = new Set(['script', 'stylesheet', 'font'])

const describe = (response: Response): Noise | null => {
	const status = response.status()
	const request = response.request()
	if (status >= 500 || (status >= 400 && STATIC_FILES.has(request.resourceType()))) {
		return { kind: 'response', text: `${status} ${request.method()} ${response.url()}`, ours: OURS.test(response.url()) }
	}
	return null
}

export type BrowserNoise = {
	/** Watch a context the test opened itself; the test's own is watched already. */
	watch(context: BrowserContext): void
	/** Let this test meet noise of this app's it causes on purpose, saying why. */
	allow(kind: NoiseKind, pattern: RegExp, reason: string): void
}

export const test = base.extend<{ browserNoise: BrowserNoise }>({
	browserNoise: [async ({ context }, use, testInfo) => {
		const seen: Noise[] = []
		const allowed: Allowance[] = []
		const watched = new WeakSet<BrowserContext>()

		const watch = (watchedContext: BrowserContext): void => {
			if (watched.has(watchedContext)) {
				return
			}
			watched.add(watchedContext)
			watchedContext.on('console', (message) => {
				// Chrome's own line for an answer of 400 or more: the answer is
				// judged on its own, below.
				if (message.type() !== 'error' || message.text().startsWith('Failed to load resource:')) {
					return
				}
				const where = message.location().url
				const text = where !== '' ? `${message.text()} (${where})` : message.text()
				seen.push({ kind: 'console', text, ours: OURS.test(text) })
			})
			watchedContext.on('weberror', (webError) => {
				const error = webError.error()
				const text = error.stack ?? error.message
				seen.push({ kind: 'pageerror', text, ours: OURS.test(text) })
			})
			watchedContext.on('requestfailed', (request) => {
				const failure = request.failure()?.errorText ?? 'failed'
				// Leaving a page cancels what it still had in flight.
				if (failure !== 'net::ERR_ABORTED') {
					seen.push({ kind: 'requestfailed', text: `${failure} ${request.method()} ${request.url()}`, ours: OURS.test(request.url()) })
				}
			})
			watchedContext.on('response', (response) => {
				const noise = describe(response)
				if (noise !== null) {
					seen.push(noise)
				}
			})
		}

		watch(context)
		await use({
			watch,
			allow: (kind, pattern, reason) => {
				allowed.push({ kind, pattern, reason })
			},
		})

		const others = seen.filter((noise) => !noise.ours)
		const unknown = others.filter((noise) => !isKnownElsewhere(noise))
		if (unknown.length > 0) {
			await testInfo.attach('browser-noise', {
				body: [...new Set(unknown.map((noise) => `${noise.kind}: ${noise.text}`))].join('\n\n'),
				contentType: 'text/plain',
			})
		}
		if (others.length > 0) {
			// The known ones as a count, so the summary can say how often.
			const title = testInfo.titlePath.join(' › ')
			mkdirSync(testInfo.project.outputDir, { recursive: true })
			appendFileSync(
				join(testInfo.project.outputDir, NOISE_FILE),
				others.map((noise) => JSON.stringify(isKnownElsewhere(noise)
					? { test: title, known: true }
					: { test: title, kind: noise.kind, text: noise.text }) + '\n').join(''),
			)
		}
		// A test that failed already says what went wrong; this would only
		// bury it.
		if (testInfo.status !== testInfo.expectedStatus) {
			return
		}
		const unexpected = seen.filter((noise) => noise.ours && !allowed.some((allowance) => allowance.kind === noise.kind && allowance.pattern.test(noise.text)))
		expect(unexpected.map(({ kind, text }) => ({ kind, text })), 'This app left an error in the browser that no assertion looked at. Fix it, or allow it with a reason.').toEqual([])
	}, { auto: true }],
})

export { expect }
