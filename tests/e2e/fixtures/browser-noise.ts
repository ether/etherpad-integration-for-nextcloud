/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { appendFileSync, mkdirSync } from 'node:fs'
import { test as base, expect, type BrowserContext, type ConsoleMessage, type Request, type Response, type WebError } from '@playwright/test'
import {
	KNOWN_ELSEWHERE,
	consoleIsOurs,
	errorIsOurs,
	matching,
	recordIn,
	requestFailureIsOurs,
	responseIsOurs,
	serverErrorIsOurs,
	sourceOf,
	type Allowance,
	type NoiseKind,
} from './browser-noise-rules.mjs'

/**
 * What the browser reported during a test that no assertion looked at: an
 * error on the console, an exception nothing caught, a request that failed
 * outright, a server error. A flow can pass every assertion and still
 * leave one of these behind - a script that threw after the button showed
 * up, a request the page fired and forgot. The specs import `test` from
 * here; every test with a browser context is watched.
 *
 * A test fails on what comes from this app: its scripts, its routes and
 * their answers (browser-noise-rules.mjs says which is which). The app
 * runs inside Nextcloud next to other apps, whose noise is not this app's
 * to fix, and which would turn runs red with every Nextcloud release. On
 * Nextcloud 34 alone the Files app's service worker, the Viewer
 * registering each handler twice, Text's rich workspace and a modal's
 * focus trap log errors on pages this app hardly touches. Those, known and
 * explained, are counted and left out. Anything else goes into the test's
 * report, as `browser-noise`, where it can be read without failing anyone,
 * and into one record for the whole run (`browser-noise.jsonl` in the
 * output directory), which browser-noise-summary.mjs groups for the CI
 * run's summary: so the noise a new release brings stands out, and the
 * known does not repeat itself.
 *
 * What a test causes on purpose it allows itself, through
 * `browserNoise.allow()`.
 */
export type { NoiseKind }

/** What the browser reported, where it came from, and whether it is this app's. */
type Noise = { kind: NoiseKind, text: string, where: string, ours: boolean }

/**
 * A static file the page cannot load is a broken page, whatever the
 * status. An API call answered with 4xx is an answer, and the specs check
 * those themselves.
 */
const STATIC_FILES = new Set(['script', 'stylesheet', 'font'])

/** A response worth reporting, or null; server errors are judged by their body. */
const describe = async (response: Response): Promise<Noise | null> => {
	const status = response.status()
	const request = response.request()
	const url = response.url()
	const text = `${status} ${request.method()} ${url}`
	if (status >= 500) {
		const body = await response.text().catch(() => '')
		return { kind: 'response', text, where: '', ours: serverErrorIsOurs(url, status, body) }
	}
	if (status >= 400 && STATIC_FILES.has(request.resourceType())) {
		return { kind: 'response', text, where: '', ours: responseIsOurs(url) }
	}
	return null
}

export type BrowserNoise = {
	/** Watch a context the test opened itself; the test's own is watched already. */
	watch(context: BrowserContext): void
	/** Let this test meet noise of this app's it causes on purpose, saying why. */
	allow(kind: NoiseKind, pattern: RegExp, reason: string): void
}

/**
 * Waits for the answers still being read: a server error is judged by its
 * body, which has to be read while its context is open.
 */
const settlers = new WeakMap<BrowserNoise, () => Promise<void>>()

export const test = base.extend<{ browserNoise: BrowserNoise }>({
	browserNoise: async ({}, use, testInfo) => {
		const seen: Noise[] = []
		const pending: Promise<void>[] = []
		const allowed: Allowance[] = []
		const started = new WeakMap<Request, number>()
		// Taken off again at the end: a context Playwright reuses for the
		// next test would otherwise collect for every test before it too.
		const detach: (() => void)[] = []

		const watch = (context: BrowserContext): void => {
			const onRequest = (request: Request): void => {
				started.set(request, Date.now())
			}
			const onConsole = (message: ConsoleMessage): void => {
				// Chrome's own line for an answer of 400 or more: the answer is
				// judged on its own, below.
				if (message.type() !== 'error' || message.text().startsWith('Failed to load resource:')) {
					return
				}
				const where = message.location().url
				const text = where !== '' ? `${message.text()} (${where})` : message.text()
				seen.push({ kind: 'console', text, where, ours: consoleIsOurs(message.text(), where) })
			}
			const onWebError = (webError: WebError): void => {
				const error = webError.error()
				const text = error.stack ?? error.message
				seen.push({ kind: 'pageerror', text, where: sourceOf(text), ours: errorIsOurs(text) })
			}
			const onRequestFailed = (request: Request): void => {
				const failure = request.failure()?.errorText ?? 'failed'
				const elapsedMs = Date.now() - (started.get(request) ?? Date.now())
				const ours = requestFailureIsOurs(failure, request.url(), elapsedMs)
				// Leaving a page cancels what it still had in flight; only an
				// abort of this app's after the client's timeout is worth a word.
				if (failure !== 'net::ERR_ABORTED' || ours) {
					seen.push({ kind: 'requestfailed', text: `${failure} after ${Math.round(elapsedMs / 100) / 10} s: ${request.method()} ${request.url()}`, where: '', ours })
				}
			}
			const onResponse = (response: Response): void => {
				pending.push(describe(response).then((noise) => {
					if (noise !== null) {
						seen.push(noise)
					}
				}))
			}
			context.on('request', onRequest)
			context.on('console', onConsole)
			context.on('weberror', onWebError)
			context.on('requestfailed', onRequestFailed)
			context.on('response', onResponse)
			detach.push(() => {
				context.off('request', onRequest)
				context.off('console', onConsole)
				context.off('weberror', onWebError)
				context.off('requestfailed', onRequestFailed)
				context.off('response', onResponse)
			})
		}

		const browserNoise: BrowserNoise = {
			watch,
			allow: (kind, pattern, reason) => {
				allowed.push({ kind, pattern, reason })
			},
		}
		settlers.set(browserNoise, async () => {
			await Promise.all(pending)
		})
		await use(browserNoise)
		detach.forEach((off) => off())
		await Promise.all(pending)

		const bodyPassed = testInfo.status === testInfo.expectedStatus
		const unexpected = seen.filter((noise) => noise.ours && matching(allowed, noise) === undefined)
		if (unexpected.length > 0) {
			// When the body failed this is not asserted, but it is often why:
			// a route that answered 500 before the button a locator waits for.
			await testInfo.attach('browser-errors-of-this-app', {
				body: unexpected.map((noise) => `${noise.kind}: ${noise.text}`).join('\n\n'),
				contentType: 'text/plain',
			})
		}
		const others = seen.filter((noise) => !noise.ours)
		const unknown = others.filter((noise) => matching(KNOWN_ELSEWHERE, noise) === undefined)
		if (unknown.length > 0) {
			await testInfo.attach('browser-noise', {
				body: [...new Set(unknown.map((noise) => `${noise.kind}: ${noise.text}`))].join('\n\n'),
				contentType: 'text/plain',
			})
		}
		// The run's record holds the attempt that counts: one that passes,
		// guard and all, or the last one. A retry would otherwise count the
		// same noise twice.
		const last = (bodyPassed && unexpected.length === 0) || testInfo.retry >= testInfo.project.retries
		if (others.length > 0 && last) {
			const title = testInfo.titlePath.join(' › ')
			mkdirSync(testInfo.project.outputDir, { recursive: true })
			appendFileSync(
				recordIn(testInfo.project.outputDir),
				others.map((noise) => {
					const known = matching(KNOWN_ELSEWHERE, noise)
					return JSON.stringify(known !== undefined
						? { test: title, known: known.id }
						: { test: title, kind: noise.kind, text: noise.text, where: noise.where }) + '\n'
				}).join(''),
			)
		}
		// A test that failed already says what went wrong; asserting this
		// too would only bury it. The attachment above keeps it.
		if (!bodyPassed) {
			return
		}
		expect(unexpected.map(({ kind, text }) => ({ kind, text })), 'This app left an error in the browser that no assertion looked at. Fix it, or allow it with a reason.').toEqual([])
	},
	// The test's own context, watched from the moment it opens. Only a test
	// that has a browser has one: an API spec opens none.
	context: async ({ context, browserNoise }, use) => {
		browserNoise.watch(context)
		await use(context)
		await settlers.get(browserNoise)?.()
	},
})

export { expect }
