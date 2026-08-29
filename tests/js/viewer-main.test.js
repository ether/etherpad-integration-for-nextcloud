/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

// The viewer component is a version-agnostic Vue options object that NC's
// Viewer mounts at runtime; we don't bundle Vue. Rather than spin up a Vue
// runtime, we exercise the options object directly: computed getters and
// methods are plain functions invoked against a controlled `this`, and the
// render function is driven with a mock `createElement` so we can assert the
// produced vnode tree. The component is captured by stubbing
// `OCA.Viewer.registerHandler` before importing the module (which registers
// on load) — no source export needed.

vi.mock('../../src/lib/oc-compat.js', () => ({
	ocGenerateUrl: (path) => path,
	ocRequestToken: () => 'csrf-token',
	translate: (text) => text,
}))
vi.mock('../../src/lib/pad-sync.js', () => ({
	createPadSync: vi.fn(() => ({
		configure: vi.fn(),
		installLifecycleHandlers: vi.fn(),
		removeLifecycleHandlers: vi.fn(),
		start: vi.fn(),
		stop: vi.fn(),
		fireAndForget: vi.fn(),
	})),
}))
vi.mock('../../src/lib/api-client.js', () => ({
	apiFindOriginalPad: vi.fn(),
	apiRecoverFromSnapshot: vi.fn(),
	apiResolvePadByPath: vi.fn(),
}))
vi.mock('../../src/lib/sanitize-html.js', () => ({
	sanitizeSnapshotHtml: vi.fn((html) => `SANITIZED:${html}`),
}))
vi.mock('../../src/lib/pad-frame-srcdoc.js', () => ({
	buildPadFrameSrcdoc: vi.fn((url) => `SRCDOC:${url}`),
}))
vi.mock('../../src/lib/urls.js', () => ({
	parsePadPathFromDavHref: vi.fn(() => ''),
	parsePublicShareTokenFromLocation: vi.fn(() => ''),
}))

const { apiFindOriginalPad, apiRecoverFromSnapshot, apiResolvePadByPath } = await import('../../src/lib/api-client.js')
const { sanitizeSnapshotHtml } = await import('../../src/lib/sanitize-html.js')
const { parsePadPathFromDavHref, parsePublicShareTokenFromLocation } = await import('../../src/lib/urls.js')

let component

beforeAll(async () => {
	window.OCA = {
		Viewer: {
			availableHandlers: [],
			registerHandler: (handler) => { component = handler.component },
		},
	}
	await import('../../src/viewer-main.js')
})

beforeEach(() => {
	vi.clearAllMocks()
	parsePublicShareTokenFromLocation.mockReturnValue('')
	parsePadPathFromDavHref.mockReturnValue('')
})

afterEach(() => {
	vi.unstubAllGlobals()
	window.happyDOM?.setURL?.('http://localhost/')
})

// Build a non-reactive stand-in for a mounted instance: data fields seeded
// from data(), computed exposed as live getters, methods bound to the same
// context. Overrides seed props/data before the getters are wired.
function makeInstance(overrides = {}) {
	const ctx = {
		filename: '',
		basename: '',
		source: '',
		fileid: null,
		fileId: null,
		fileInfo: null,
		...component.data(),
		$emit: vi.fn(),
		...overrides,
	}
	for (const [key, getter] of Object.entries(component.computed)) {
		Object.defineProperty(ctx, key, { configurable: true, get: () => getter.call(ctx) })
	}
	for (const [key, fn] of Object.entries(component.methods)) {
		ctx[key] = (...args) => fn.call(ctx, ...args)
	}
	return ctx
}

const jsonResponse = (body, ok = true, status = 200) => ({
	ok,
	status,
	json: () => Promise.resolve(body),
})

// Drain queued microtasks so fire-and-forget continuations (e.g. the
// original-pad hint lookup) settle before we assert on their results.
const flush = async () => {
	for (let i = 0; i < 8; i += 1) await Promise.resolve()
}

// `toContain('/pads/open')` is also true of '/pads/open-by-id', and
// '/pads/initialize' of '/pads/initialize-by-id/42' — an assertion that
// cannot fail. ocGenerateUrl is mocked as identity, so the endpoint is
// the whole path and can be compared outright.
const endpoint = (name) => `/apps/etherpad_nextcloud/api/v1/${name}`
const bodyOf = (call) => String(call?.[1]?.body || '')

const stubFetch = (impl) => {
	const mock = typeof impl === 'function' ? vi.fn(impl) : vi.fn().mockResolvedValue(impl)
	vi.stubGlobal('fetch', mock)
	return mock
}

// --- mock createElement + vnode-tree query helpers ---
const h = (tag, data, children) => ({
	tag,
	data: data || {},
	children: children == null
		? []
		: (Array.isArray(children) ? children.filter((c) => c != null) : [children]),
})

const hasClass = (node, cls) =>
	typeof node?.data?.class === 'string' && node.data.class.split(/\s+/).includes(cls)

const walk = (node, visit) => {
	if (!node || typeof node !== 'object') return
	visit(node)
	for (const child of node.children || []) walk(child, visit)
}

const findByClass = (root, cls) => {
	let found = null
	walk(root, (n) => { if (!found && hasClass(n, cls)) found = n })
	return found
}

const findByTag = (root, tag) => {
	const out = []
	walk(root, (n) => { if (n.tag === tag) out.push(n) })
	return out
}

const allText = (root) => {
	const parts = []
	walk(root, (n) => {
		for (const child of n.children || []) {
			if (typeof child === 'string') parts.push(child)
		}
	})
	return parts.join(' ')
}

describe('viewer component — computed path/id derivation', () => {
	it('derives filePath from a .pad fileInfo.path', () => {
		const vm = makeInstance({ fileInfo: { path: '/Notes/Standup.pad' } })
		expect(vm.filePath).toBe('/Notes/Standup.pad')
	})

	it('falls back to filename joined with the dir from the URL when fileInfo is absent', () => {
		const vm = makeInstance({ filename: 'Plan.pad' })
		expect(vm.filePath).toBe('/Plan.pad')
	})

	it('prefers the DAV-parsed source path when it is a .pad', () => {
		parsePadPathFromDavHref.mockReturnValue('/From/Dav.pad')
		const vm = makeInstance({ source: 'https://nc/remote.php/dav/files/u/From/Dav.pad' })
		expect(vm.filePath).toBe('/From/Dav.pad')
	})

	// Nextcloud accepts these names: its validator trims only to decide
	// whether a name is empty or `.`/`..`, and judges the rest on the name
	// as given. A viewer that trims asks for a neighbouring file instead,
	// which is the plus-sign bug reached by a different character.
	it('keeps a space before the extension', () => {
		const vm = makeInstance({ fileInfo: { path: '/Notes/Standup .pad' } })
		expect(vm.filePath).toBe('/Notes/Standup .pad')
	})

	it('keeps a leading space in the file name', () => {
		const vm = makeInstance({ filename: ' A.pad' })
		expect(vm.filePath).toBe('/ A.pad')
	})

	it('keeps a trailing space in the directory name', () => {
		const vm = makeInstance({ filename: 'A.pad', fileInfo: { dirname: '/Folder ' } })
		expect(vm.filePath).toBe('/Folder /A.pad')
	})

	it('keeps a trailing space in a directory taken from the URL', () => {
		window.happyDOM.setURL('http://localhost/apps/files?dir=' + encodeURIComponent('/Folder '))
		const vm = makeInstance({ filename: 'A.pad' })
		expect(vm.filePath).toBe('/Folder /A.pad')
	})

	it('returns empty filePath when nothing resolves to a .pad', () => {
		const vm = makeInstance({ filename: 'notes.txt', basename: '' })
		expect(vm.filePath).toBe('/notes.txt') // non-pad falls through to "/" + baseName
		const empty = makeInstance({})
		expect(empty.filePath).toBe('')
	})

	it('resolves a positive numeric fileId from props, preferring fileid', () => {
		expect(makeInstance({ fileid: '42' }).resolvedFileId).toBe(42)
		expect(makeInstance({ fileId: 7 }).resolvedFileId).toBe(7)
		expect(makeInstance({ fileInfo: { id: 9 } }).resolvedFileId).toBe(9)
	})

	it('returns null resolvedFileId when no positive id is available', () => {
		expect(makeInstance({ fileid: 0 }).resolvedFileId).toBeNull()
		expect(makeInstance({}).resolvedFileId).toBeNull()
	})

	// The Files URL carries an id too, and this used to read it when
	// `openfile=true`. That id belongs to whatever the route was opened
	// with, while filePath follows the file the Viewer is showing — they
	// part company on the next/previous arrows, and openfile=true survives
	// until the viewer closes. With opening by id no longer retried by
	// path, the URL's id would silently decide which document appears.
	it('ignores the file id in the Files URL, whatever openfile says', () => {
		window.happyDOM.setURL('http://localhost/apps/files/files/77?openfile=true')
		expect(makeInstance({}).resolvedFileId).toBeNull()

		window.happyDOM.setURL('http://localhost/apps/files/files/77')
		expect(makeInstance({}).resolvedFileId).toBeNull()
	})

	it('still takes an id the Viewer supplies for that file', () => {
		window.happyDOM.setURL('http://localhost/apps/files/files/77?openfile=true')
		expect(makeInstance({ fileInfo: { id: 9, path: '/x.pad' } }).resolvedFileId).toBe(9)
	})
})

// Both inputs change together on a file swap. Watching them separately
// fired two opens, and the generation guard drops the loser's result
// without cancelling its request — for a protected pad that is a second
// Etherpad session and cookie nothing consumes.
describe('viewer component — open key', () => {
	it('watches a single key', () => {
		expect(Object.keys(component.watch)).toEqual(['openKey'])
		expect(component.watch.openKey.immediate).toBe(true)
	})

	it('changes when either the path or the id changes', () => {
		const base = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })
		const otherId = makeInstance({ fileid: 43, fileInfo: { path: '/x.pad' } })
		const otherPath = makeInstance({ fileid: 42, fileInfo: { path: '/y.pad' } })

		expect(base.openKey).not.toBe(otherId.openKey)
		expect(base.openKey).not.toBe(otherPath.openKey)
		expect(base.openKey).toBe(makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } }).openKey)
	})
})

describe('viewer component — resolveOpenUrl', () => {
	it('happy path: sets iframeSrc, starts sync, and emits loaded', async () => {
		stubFetch(jsonResponse({ url: 'https://pad.example/p', sync_url: 'https://sync.example', sync_interval_seconds: 60 }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.iframeSrc).toBe('https://pad.example/p')
		expect(vm.isLoading).toBe(false)
		expect(vm.loadError).toBe('')
		expect(vm.$emit).toHaveBeenCalledWith('update:loaded', true)
		expect(vm._padSync.configure).toHaveBeenCalledWith({ syncUrl: 'https://sync.example', intervalMs: 60000 })
		expect(vm._padSync.installLifecycleHandlers).toHaveBeenCalled()
		expect(vm._padSync.start).toHaveBeenCalled()
	})

	it('does not start the sync loop when the API returns no sync_url', async () => {
		stubFetch(jsonResponse({ url: 'https://pad.example/p', sync_url: '' }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.iframeSrc).toBe('https://pad.example/p')
		expect(vm._padSync.start).not.toHaveBeenCalled()
	})

	it('external pad: enters external snapshot mode with the stored snapshot', async () => {
		stubFetch(jsonResponse({
			url: 'https://other.server/p/abc',
			is_external: true,
			snapshot_text: 'hello',
			snapshot_html: '<b>hello</b>',
		}))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.snapshotMode).toBe('external')
		expect(vm.externalOpenUrl).toBe('https://other.server/p/abc')
		expect(vm.snapshot).toEqual({ text: 'hello', html: '<b>hello</b>' })
		expect(vm.iframeSrc).toBe('')
		expect(vm.$emit).toHaveBeenCalledWith('update:loaded', true)
	})

	it('readonly snapshot: enters readonly mode without requiring a url', async () => {
		stubFetch(jsonResponse({ is_readonly_snapshot: true, snapshot_text: 't', snapshot_html: '<i>t</i>' }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.snapshotMode).toBe('readonly')
		expect(vm.snapshot).toEqual({ text: 't', html: '<i>t</i>' })
	})

	it('missing frontmatter: initializes once then re-opens', async () => {
		const fetchMock = stubFetch()
		fetchMock
			.mockResolvedValueOnce(jsonResponse({ message: 'Missing YAML frontmatter' }, false, 400))
			.mockResolvedValueOnce(jsonResponse({ status: 'ok' }))
			.mockResolvedValueOnce(jsonResponse({ url: 'https://pad.example/after-init', sync_url: '' }))
		const vm = makeInstance({ fileInfo: { path: '/x.pad' } }) // resolvedFileId null -> by-path only

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(fetchMock.mock.calls[0][0]).toBe(endpoint('pads/open'))
		expect(bodyOf(fetchMock.mock.calls[0])).toBe('file=%2Fx.pad')
		expect(fetchMock.mock.calls[1][0]).toBe(endpoint('pads/initialize'))
		expect(vm.iframeSrc).toBe('https://pad.example/after-init')
		expect(vm.loadError).toBe('')
	})

	it('initializes by file id (not by path) when an id is available', async () => {
		const fetchMock = stubFetch()
		fetchMock
			.mockResolvedValueOnce(jsonResponse({ message: 'Missing YAML frontmatter' }, false, 400)) // open by-id
			.mockResolvedValueOnce(jsonResponse({ status: 'migrated_from_legacy' }))                  // initialize-by-id
			.mockResolvedValueOnce(jsonResponse({ url: 'https://pad.example/by-id', sync_url: '' }))   // re-open by-id
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		// The by-id answer is acted on directly: a 400 is a verdict about the
		// file the id named, so there is no by-path attempt in between.
		expect(fetchMock.mock.calls[0][0]).toBe(endpoint('pads/open-by-id'))
		expect(fetchMock.mock.calls[1][0]).toBe(endpoint('pads/initialize-by-id/42'))
		expect(vm.iframeSrc).toBe('https://pad.example/by-id')
	})

	// Opening by id exists so that the wrong document cannot be opened. A
	// by-path retry after a by-id failure puts that back: the second, weaker
	// question can succeed where the first was refused. The server answers a
	// plain 404 both for an id that is gone and for one outside the user's
	// own tree — it cannot separate them without disclosing that the file
	// exists — so no by-id failure is safe to retry by path.
	it('surfaces an unresolvable file id instead of opening the file at the path', async () => {
		const fetchMock = stubFetch()
		fetchMock.mockResolvedValueOnce(jsonResponse({ message: 'Cannot open selected .pad file.' }, false, 404))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][0]).toBe(endpoint('pads/open-by-id'))
		expect(bodyOf(fetchMock.mock.calls[0])).toBe('fileId=42')
		expect(vm.loadError).toBe('Cannot open selected .pad file.')
		expect(vm.iframeSrc).toBe('')
		// Not a dead end: the one thing that can help is named.
		expect(vm.maybeStaleFileId).toBe(true)
	})

	it('does not suggest a reload for an error that a reload cannot fix', async () => {
		stubFetch(jsonResponse({ message: 'Could not open pad' }, false, 500))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.maybeStaleFileId).toBe(false)
	})

	it('does not suggest a reload on a public share, where no id was sent', async () => {
		parsePublicShareTokenFromLocation.mockReturnValue('share-token')
		stubFetch(jsonResponse({ message: 'The selected file does not exist in this share.' }, false, 404))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.maybeStaleFileId).toBe(false)
	})

	it('opens by path when no file id is available at all', async () => {
		const fetchMock = stubFetch(jsonResponse({ url: 'https://pad.example/by-path', sync_url: '' }))
		const vm = makeInstance({ fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][0]).toBe(endpoint('pads/open'))
		expect(bodyOf(fetchMock.mock.calls[0])).toBe('file=%2Fx.pad')
		expect(vm.iframeSrc).toBe('https://pad.example/by-path')
	})

	// Nextcloud's own layers answer before the controller does. The request
	// asks for JSON, so both come back as JSON rather than an HTML page:
	// SecurityMiddleware returns a JSONResponse when Accept does not name
	// html, and requireUser maps an absent session the same way.
	it('surfaces an expired session instead of retrying by path', async () => {
		const fetchMock = stubFetch(jsonResponse({ message: 'Not authenticated.' }, false, 401))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('Not authenticated.')
		expect(vm.canRecover).toBe(false)
		expect(vm.iframeSrc).toBe('')
	})

	// A stale request token is a 412 from the framework, not one of the
	// controller's own statuses — and just as unsafe to answer with a
	// second request.
	// The initialize step is a second chance for the file to disappear: the
	// open said "no frontmatter", the file moved, and the retry 404s. That
	// error has to explain itself the same way the open's would.
	it('carries the status through an initialize that no longer finds the file', async () => {
		const fetchMock = stubFetch()
		fetchMock
			.mockResolvedValueOnce(jsonResponse({ message: 'Missing YAML frontmatter' }, false, 400))
			.mockResolvedValueOnce(jsonResponse({ message: 'Cannot open selected .pad file.' }, false, 404))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(vm.loadError).toBe('Cannot open selected .pad file.')
		expect(vm.maybeStaleFileId).toBe(true)
	})

	// The generation guard only discards the loser's result; the request
	// itself used to run to completion, and for a protected pad that mints
	// an Etherpad session and cookie nothing consumes.
	it('aborts the request a superseded resolve left in flight', async () => {
		const signals = []
		// Rejects on abort, the way fetch does. A stub that resolves anyway
		// would prove the signal was passed but never exercise the
		// AbortError path — the only one a browser takes.
		stubFetch((url, init) => {
			const signal = init && init.signal
			signals.push(signal)
			if (signals.length > 1) {
				return Promise.resolve(jsonResponse({ url: 'https://current', sync_url: '' }))
			}
			return new Promise((resolve, reject) => {
				signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
			})
		})
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		const superseded = vm.resolveOpenUrl()
		const current = vm.resolveOpenUrl()
		await Promise.all([superseded, current])

		expect(signals[0].aborted).toBe(true)
		expect(signals[1].aborted).toBe(false)
		expect(vm.iframeSrc).toBe('https://current')
		// The abort is bookkeeping, not a failure the user should read.
		expect(vm.loadError).toBe('')
		expect(vm.isLoading).toBe(false)
	})

	it('sends Accept: application/json on a POST open, not only on a GET', async () => {
		const fetchMock = stubFetch(jsonResponse({ url: 'https://pad.example/p', sync_url: '' }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		const sent = fetchMock.mock.calls[0][1].headers
		expect(sent.Accept).toBe('application/json')
		expect(sent['Content-Type']).toBe('application/x-www-form-urlencoded;charset=UTF-8')
		expect(sent.requesttoken).toBe('csrf-token')
	})

	it('aborts the open when the viewer is torn down mid-request', async () => {
		let captured
		stubFetch((url, init) => {
			captured = init && init.signal
			return new Promise(() => {})
		})
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		void vm.resolveOpenUrl()
		await Promise.resolve()
		component.beforeDestroy.call(vm)

		expect(captured.aborted).toBe(true)
	})

	it('surfaces a stale request token instead of retrying by path', async () => {
		const fetchMock = stubFetch(jsonResponse({ message: 'CSRF check failed' }, false, 412))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('CSRF check failed')
		expect(vm.iframeSrc).toBe('')
	})

	// A public share resolves inside the share, by name — the id branch must
	// not take precedence there even when the Viewer hands one over. See #204.
	it('uses the public-share route even when a file id is available', async () => {
		parsePublicShareTokenFromLocation.mockReturnValue('share-token')
		const fetchMock = stubFetch(jsonResponse({ url: 'https://pad.example/public', sync_url: '' }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][0]).toContain('/api/v1/public/open/share-token')
		expect(fetchMock.mock.calls[0][0]).toContain('file=%2Fx.pad')
		expect(vm.iframeSrc).toBe('https://pad.example/public')
	})

	it('surfaces a payload without a usable URL instead of retrying by path', async () => {
		const fetchMock = stubFetch(jsonResponse({ sync_url: 'https://sync.example' }))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('Pad open API did not return a valid URL.')
		expect(vm.iframeSrc).toBe('')
	})

	it('surfaces a server error from the by-id open instead of retrying by path', async () => {
		const fetchMock = stubFetch()
		fetchMock.mockResolvedValueOnce(jsonResponse({ message: 'Could not open pad' }, false, 500))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('Could not open pad')
		expect(vm.canRecover).toBe(false)
		expect(vm.iframeSrc).toBe('')
	})

	it('offers recovery on a labelled error rather than opening the path', async () => {
		const fetchMock = stubFetch()
		fetchMock.mockResolvedValueOnce(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		apiFindOriginalPad.mockResolvedValue({ found: false })
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()
		await flush()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('no binding')
		expect(vm.canRecover).toBe(true)
	})

	it('surfaces a network failure from the by-id open instead of retrying by path', async () => {
		const fetchMock = stubFetch(() => Promise.reject(new Error('Failed to fetch')))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.loadError).toBe('Failed to fetch')
	})

	it('missing_binding for an addressable, non-public file: offers recovery and looks up the original', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		apiFindOriginalPad.mockResolvedValue({ found: true, viewer_url: 'https://nc/viewer/123', path: '/orig.pad' })
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()
		await flush() // let the fire-and-forget original-pad hint settle

		expect(vm.loadError).toBe('no binding')
		expect(vm.canRecover).toBe(true)
		expect(apiFindOriginalPad).toHaveBeenCalledWith(42)
		expect(vm.originalPad).toEqual({ viewerUrl: 'https://nc/viewer/123', path: '/orig.pad' })
		expect(vm.isCheckingOriginal).toBe(false)
	})

	// Dropping the Files-URL id took recovery's address with it. It is asked
	// for by the path on screen instead — the same file by construction,
	// where the URL's id need not have been.
	it('resolves recovery\'s file id from the path when the Viewer supplies none', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		apiResolvePadByPath.mockResolvedValue({ file_id: 99 })
		apiFindOriginalPad.mockResolvedValue({ found: false })
		const vm = makeInstance({ fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()
		await flush()

		expect(apiResolvePadByPath).toHaveBeenCalledWith('/copy.pad')
		expect(vm.recoveryFileId).toBe(99)
		expect(vm.canRecover).toBe(true)
		expect(apiFindOriginalPad).toHaveBeenCalledWith(99)
	})

	it('offers no recovery when the path cannot be resolved either', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		apiResolvePadByPath.mockRejectedValue(new Error('resolve failed'))
		const vm = makeInstance({ fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()
		await flush()

		expect(vm.recoveryFileId).toBeNull()
		expect(vm.canRecover).toBe(false)
	})

	it('does not ask for an id it already has', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		apiFindOriginalPad.mockResolvedValue({ found: false })
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()
		await flush()

		expect(apiResolvePadByPath).not.toHaveBeenCalled()
		expect(vm.recoveryFileId).toBe(42)
	})

	it('does not offer recovery on a public share even with missing_binding', async () => {
		parsePublicShareTokenFromLocation.mockReturnValue('share-token')
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 400))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.canRecover).toBe(false)
		expect(apiFindOriginalPad).not.toHaveBeenCalled()
	})

	it('aborts cleanly when a newer resolve generation supersedes this one', async () => {
		let release
		const gate = new Promise((resolve) => { release = resolve })
		stubFetch(() => gate.then(() => jsonResponse({ url: 'https://stale', sync_url: '' })))
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/x.pad' } })

		const pending = vm.resolveOpenUrl()
		vm.resolveGeneration += 1 // a newer resolve started
		release()
		await pending

		expect(vm.iframeSrc).toBe('') // stale result discarded
	})
})

describe('viewer component — recoverFromSnapshot', () => {
	it('posts the recovery, clears the error, and re-resolves', async () => {
		apiRecoverFromSnapshot.mockResolvedValue({})
		const vm = makeInstance({ fileid: 42, recoveryFileId: 42, canRecover: true, loadError: 'boom' })
		vm.resolveOpenUrl = vi.fn().mockResolvedValue()

		await vm.recoverFromSnapshot()

		expect(apiRecoverFromSnapshot).toHaveBeenCalledWith(42)
		expect(vm.loadError).toBe('')
		expect(vm.canRecover).toBe(false)
		expect(vm.resolveOpenUrl).toHaveBeenCalled()
		expect(vm.isRecovering).toBe(false)
	})

	it('is a no-op when recovery is not available', async () => {
		const vm = makeInstance({ fileid: 42, recoveryFileId: 42, canRecover: false })
		vm.resolveOpenUrl = vi.fn()

		await vm.recoverFromSnapshot()

		expect(apiRecoverFromSnapshot).not.toHaveBeenCalled()
		expect(vm.resolveOpenUrl).not.toHaveBeenCalled()
	})

	it('surfaces the error and stops the spinner when recovery fails', async () => {
		apiRecoverFromSnapshot.mockRejectedValue(new Error('recover failed'))
		const vm = makeInstance({ fileid: 42, recoveryFileId: 42, canRecover: true })
		vm.resolveOpenUrl = vi.fn()

		await vm.recoverFromSnapshot()

		expect(vm.loadError).toBe('recover failed')
		expect(vm.isRecovering).toBe(false)
	})
})

describe('viewer component — teardown', () => {
	it('flushes, stops, and unhooks the sync controller on beforeUnmount', () => {
		const vm = makeInstance({})
		const sync = vm.padSync() // lazily create the controller
		component.beforeUnmount.call(vm)

		expect(sync.fireAndForget).toHaveBeenCalledWith(true, true)
		expect(sync.stop).toHaveBeenCalled()
		expect(sync.removeLifecycleHandlers).toHaveBeenCalled()
	})

	it('does not construct a controller just to tear it down', () => {
		const vm = makeInstance({})
		expect(() => component.beforeDestroy.call(vm)).not.toThrow()
		expect(vm._padSync).toBeUndefined()
	})
})

describe('viewer component — render', () => {
	it('renders the error card with title and message', () => {
		const vm = makeInstance({ loadError: 'Boom' })
		const tree = component.render.call(vm, h)

		expect(findByClass(tree, 'epnc-native-status--error')).toBeTruthy()
		expect(allText(tree)).toContain('Could not open pad')
		expect(allText(tree)).toContain('Boom')
	})

	// The only error-card branch the assertions above do not reach: deleting
	// the block would leave every other case green.
	it('renders the reload hint when the file id may be stale', () => {
		const vm = makeInstance({ loadError: 'Cannot open selected .pad file.', maybeStaleFileId: true })
		const tree = component.render.call(vm, h)

		expect(allText(tree)).toContain('Cannot open selected .pad file.')
		expect(allText(tree)).toContain('may have been moved or replaced')
	})

	it('leaves the reload hint out for every other error', () => {
		const vm = makeInstance({ loadError: 'Could not open pad', maybeStaleFileId: false })
		const tree = component.render.call(vm, h)

		expect(allText(tree)).not.toContain('may have been moved or replaced')
	})

	it('shows the "checking for the original" hint while the lookup is in flight', () => {
		const vm = makeInstance({ loadError: 'x', canRecover: true, isCheckingOriginal: true })
		const tree = component.render.call(vm, h)

		expect(allText(tree)).toContain('Checking for the original pad...')
		expect(findByTag(tree, 'button')).toHaveLength(0)
	})

	it('offers "Open the original" plus a create action when an original was found', () => {
		const vm = makeInstance({
			loadError: 'x',
			canRecover: true,
			originalPad: { viewerUrl: 'https://nc/viewer/9', path: '/o.pad' },
		})
		const tree = component.render.call(vm, h)

		const link = findByTag(tree, 'a')[0]
		expect(link.data.attrs.href).toBe('https://nc/viewer/9')
		expect(allText(tree)).toContain('Open the original .pad file')
		expect(findByTag(tree, 'button')).toHaveLength(1)
	})

	it('offers only a create action when no original was found', () => {
		const vm = makeInstance({ loadError: 'x', canRecover: true, originalPad: null })
		const tree = component.render.call(vm, h)

		expect(findByTag(tree, 'a')).toHaveLength(0)
		expect(findByTag(tree, 'button')).toHaveLength(1)
		expect(allText(tree)).toContain('Create new pad from this file')
	})

	it('renders the external snapshot view with a sanitized body and an open-original link', () => {
		const vm = makeInstance({
			snapshotMode: 'external',
			externalOpenUrl: 'https://other/p',
			externalOpenMessage: 'msg',
			snapshot: { text: 'plain', html: '<b>x</b>' },
		})
		const tree = component.render.call(vm, h)

		expect(allText(tree)).toContain('Pad from another server')
		expect(findByTag(tree, 'a')[0].data.attrs.href).toBe('https://other/p')
		expect(sanitizeSnapshotHtml).toHaveBeenCalledWith('<b>x</b>')
		expect(findByClass(tree, 'epnc-native-snapshot__text--html').data.domProps.innerHTML).toBe('SANITIZED:<b>x</b>')
	})

	it('renders the readonly snapshot view', () => {
		const vm = makeInstance({ snapshotMode: 'readonly', snapshot: { text: 't', html: '' } })
		const tree = component.render.call(vm, h)

		expect(allText(tree)).toContain('Read-only snapshot')
	})

	it('renders the loading placeholder while resolving', () => {
		const vm = makeInstance({ isLoading: true })
		const tree = component.render.call(vm, h)

		expect(allText(tree)).toContain('Loading pad...')
	})

	it('renders the iframe shell with a srcdoc wrapper once a pad URL is set', () => {
		const vm = makeInstance({ isLoading: false, iframeSrc: 'https://pad/p' })
		const tree = component.render.call(vm, h)

		const iframe = findByTag(tree, 'iframe')[0]
		expect(iframe.data.attrs.srcdoc).toBe('SRCDOC:https://pad/p')
		expect(iframe.data.attrs.title).toBe('Etherpad')
	})
})
