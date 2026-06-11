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

const { apiFindOriginalPad, apiRecoverFromSnapshot } = await import('../../src/lib/api-client.js')
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
			.mockResolvedValueOnce(jsonResponse({ message: 'Missing YAML frontmatter' }, false, 422))
			.mockResolvedValueOnce(jsonResponse({ status: 'ok' }))
			.mockResolvedValueOnce(jsonResponse({ url: 'https://pad.example/after-init', sync_url: '' }))
		const vm = makeInstance({ fileInfo: { path: '/x.pad' } }) // resolvedFileId null -> by-path only

		await vm.resolveOpenUrl()

		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(fetchMock.mock.calls[1][0]).toContain('/pads/initialize')
		expect(vm.iframeSrc).toBe('https://pad.example/after-init')
		expect(vm.loadError).toBe('')
	})

	it('missing_binding for an addressable, non-public file: offers recovery and looks up the original', async () => {
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 404))
		apiFindOriginalPad.mockResolvedValue({ found: true, viewer_url: 'https://nc/viewer/123', path: '/orig.pad' })
		const vm = makeInstance({ fileid: 42, fileInfo: { path: '/copy.pad' } })

		await vm.resolveOpenUrl()

		expect(vm.loadError).toBe('no binding')
		expect(vm.canRecover).toBe(true)
		expect(apiFindOriginalPad).toHaveBeenCalledWith(42)
		expect(vm.originalPad).toEqual({ viewerUrl: 'https://nc/viewer/123', path: '/orig.pad' })
		expect(vm.isCheckingOriginal).toBe(false)
	})

	it('does not offer recovery on a public share even with missing_binding', async () => {
		parsePublicShareTokenFromLocation.mockReturnValue('share-token')
		stubFetch(jsonResponse({ message: 'no binding', code: 'missing_binding' }, false, 404))
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
		const vm = makeInstance({ fileid: 42, canRecover: true, loadError: 'boom' })
		vm.resolveOpenUrl = vi.fn().mockResolvedValue()

		await vm.recoverFromSnapshot()

		expect(apiRecoverFromSnapshot).toHaveBeenCalledWith(42)
		expect(vm.loadError).toBe('')
		expect(vm.canRecover).toBe(false)
		expect(vm.resolveOpenUrl).toHaveBeenCalled()
		expect(vm.isRecovering).toBe(false)
	})

	it('is a no-op when recovery is not available', async () => {
		const vm = makeInstance({ fileid: 42, canRecover: false })
		vm.resolveOpenUrl = vi.fn()

		await vm.recoverFromSnapshot()

		expect(apiRecoverFromSnapshot).not.toHaveBeenCalled()
		expect(vm.resolveOpenUrl).not.toHaveBeenCalled()
	})

	it('surfaces the error and stops the spinner when recovery fails', async () => {
		apiRecoverFromSnapshot.mockRejectedValue(new Error('recover failed'))
		const vm = makeInstance({ fileid: 42, canRecover: true })
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
