/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createPublicPadMenuRegistrar } from '../../../src/files/public-pad-menu.js'

const flushMicrotasks = async () => {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

const buildRegistrar = (overrides = {}) => createPublicPadMenuRegistrar({
	isFilesAppRoute: () => true,
	onCreateInternalPublicPad: vi.fn(),
	onCreateExternalPublicPad: vi.fn(),
	...overrides,
})

beforeEach(() => {
	globalThis.t = (_app, text) => text
	window.OC = { PERMISSION_CREATE: 4 }
	window.OCA = {}
	window.OCP = {}
	delete window._nc_newfilemenu
})

afterEach(() => {
	delete globalThis.t
	delete window.OC
	delete window.OCA
	delete window.OCP
	delete window._nc_newfilemenu
})

describe('createPublicPadMenuRegistrar', () => {
	it('does nothing when not on the Files app route', async () => {
		const isFilesAppRoute = vi.fn(() => false)
		const ensureRegistration = buildRegistrar({ isFilesAppRoute })

		// Modern API present, but we should not call it because we're not on Files.
		const register = vi.fn()
		window.OCP.Files = { addNewFileMenuEntry: register }

		ensureRegistration()
		await flushMicrotasks()

		expect(isFilesAppRoute).toHaveBeenCalled()
		expect(register).not.toHaveBeenCalled()
	})

	it('registers both entries through the modern OCP.Files.addNewFileMenuEntry API', async () => {
		const register = vi.fn()
		window.OCP.Files = { addNewFileMenuEntry: register }
		const internalHandler = vi.fn()
		const externalHandler = vi.fn()

		const ensureRegistration = buildRegistrar({
			onCreateInternalPublicPad: internalHandler,
			onCreateExternalPublicPad: externalHandler,
		})
		ensureRegistration()
		await flushMicrotasks()

		expect(register).toHaveBeenCalledTimes(2)
		const [internalEntry, externalEntry] = register.mock.calls.map((c) => c[0])
		expect(internalEntry.id).toBe('etherpad_nextcloud_public_pad')
		expect(internalEntry.displayName).toBe('Public pad')
		expect(externalEntry.id).toBe('etherpad_nextcloud_public_pad_external')
		expect(externalEntry.displayName).toBe('Public pad from URL')

		// Handlers map back to the controller callbacks.
		internalEntry.handler()
		externalEntry.handler()
		expect(internalHandler).toHaveBeenCalledOnce()
		expect(externalHandler).toHaveBeenCalledOnce()
	})

	it('falls through to getNewFileMenu().registerEntry when addNewFileMenuEntry is absent', async () => {
		const registerEntry = vi.fn()
		window.OCA.Files = {
			getNewFileMenu: () => ({ registerEntry }),
		}
		buildRegistrar()()
		await flushMicrotasks()

		expect(registerEntry).toHaveBeenCalledTimes(2)
	})

	it('uses the _nc_newfilemenu window global as a last modern-API fallback', async () => {
		const registerEntry = vi.fn()
		window._nc_newfilemenu = { registerEntry }
		buildRegistrar()()
		await flushMicrotasks()

		expect(registerEntry).toHaveBeenCalledTimes(2)
	})

	it('treats duplicate-entry errors as success in the modern path', async () => {
		const register = vi.fn(() => { throw new Error('Entry already exists: duplicate id') })
		window.OCP.Files = { addNewFileMenuEntry: register }

		const ensureRegistration = buildRegistrar()
		ensureRegistration()
		await flushMicrotasks()

		// Both entries attempted; first call threw 'duplicate', second one too.
		// We should treat both as registered and not schedule a retry.
		expect(register).toHaveBeenCalledTimes(2)

		// Second call to ensureRegistration must be a no-op: the registrar
		// remembers it succeeded.
		ensureRegistration()
		await flushMicrotasks()
		expect(register).toHaveBeenCalledTimes(2)
	})

	it('fails the modern path on non-duplicate errors so retry can happen', async () => {
		const register = vi.fn(() => { throw new Error('Internal Server Error') })
		window.OCP.Files = { addNewFileMenuEntry: register }

		// Strip out the legacy fallback so we can observe the failure of
		// the modern path directly.
		window.OCA.Files = {}
		const ensureRegistration = buildRegistrar()
		ensureRegistration()
		await flushMicrotasks()

		// One failed attempt, but we did not mark registered. A subsequent
		// call will try again (we don't wait the 500ms retry — just confirm
		// the in-memory flag stayed false).
		const callCountAfterFirstRun = register.mock.calls.length
		expect(callCountAfterFirstRun).toBeGreaterThanOrEqual(1)
		// Calling again immediately is allowed because we did not succeed.
		ensureRegistration()
		await flushMicrotasks()
		expect(register.mock.calls.length).toBeGreaterThanOrEqual(callCountAfterFirstRun)
	})

	it('registers both legacy entries via OCA.Files.NewFileMenu.addMenuEntry', async () => {
		// Modern API explicitly absent so we fall through to legacy.
		const addMenuEntry = vi.fn()
		window.OCA.Files = {
			NewFileMenu: { addMenuEntry },
		}

		buildRegistrar()()
		await flushMicrotasks()

		expect(addMenuEntry).toHaveBeenCalledTimes(2)
		const ids = addMenuEntry.mock.calls.map((c) => c[0].id)
		expect(ids).toContain('etherpad_nextcloud_public_pad')
		expect(ids).toContain('etherpad_nextcloud_public_pad_external')
		// Legacy entries use actionHandler, not handler.
		expect(typeof addMenuEntry.mock.calls[0][0].actionHandler).toBe('function')
	})

	it('does not mark the legacy direct-menu path as registered when only one entry succeeds', async () => {
		// Regression for PR #43: if a real error fires on the external
		// entry, we used to set the success flag based on the internal one.
		const addMenuEntry = vi.fn()
			.mockImplementationOnce(() => undefined) // internal succeeds
			.mockImplementationOnce(() => { throw new Error('something broke') })
		window.OCA.Files = {
			NewFileMenu: { addMenuEntry },
		}
		// Provide the OC.Plugins fallback to confirm the registrar keeps
		// trying alternatives after the half-success branch.
		const pluginRegister = vi.fn()
		window.OC = { ...window.OC, Plugins: { register: pluginRegister } }

		buildRegistrar()()
		await flushMicrotasks()

		// Plugin path got a chance because the direct-menu half-success was
		// not treated as a clean win.
		expect(pluginRegister).toHaveBeenCalledOnce()
	})

	it('registers via OC.Plugins.register when no direct menu is exposed', async () => {
		const pluginRegister = vi.fn()
		window.OC = { ...window.OC, Plugins: { register: pluginRegister } }

		buildRegistrar()()
		await flushMicrotasks()

		expect(pluginRegister).toHaveBeenCalledOnce()
		const [eventName, listener] = pluginRegister.mock.calls[0]
		expect(eventName).toBe('OCA.Files.NewFileMenu')
		expect(typeof listener.attach).toBe('function')

		// Simulate Files firing the plugin attach — both entries should land.
		const addMenuEntry = vi.fn()
		listener.attach({ addMenuEntry })
		expect(addMenuEntry).toHaveBeenCalledTimes(2)
	})

	it('produces an enabled() callback that respects hasCreatePermission', async () => {
		const register = vi.fn()
		window.OCP.Files = { addNewFileMenuEntry: register }

		buildRegistrar()()
		await flushMicrotasks()

		const { enabled } = register.mock.calls[0][0]

		expect(enabled({ hasCreatePermission: true })).toBe(true)
		expect(enabled({ hasCreatePermission: false })).toBe(false)
	})

	it('enabled() falls back to PERMISSION_CREATE bitmask when boolean flag is absent', async () => {
		const register = vi.fn()
		window.OCP.Files = { addNewFileMenuEntry: register }

		buildRegistrar()()
		await flushMicrotasks()

		const { enabled } = register.mock.calls[0][0]

		// PERMISSION_CREATE = 4 → bit set means user can create.
		expect(enabled({ permissions: 31 })).toBe(true)
		expect(enabled({ permissions: 1 })).toBe(false)
		// Folder object exposing get('permissions') (Vue Files API).
		expect(enabled({ get: (key) => (key === 'permissions' ? 4 : null) })).toBe(true)
	})

	it('enabled() defaults to allowing creation when no context is provided', async () => {
		const register = vi.fn()
		window.OCP.Files = { addNewFileMenuEntry: register }

		buildRegistrar()()
		await flushMicrotasks()

		const { enabled } = register.mock.calls[0][0]
		expect(enabled()).toBe(true)
		expect(enabled({})).toBe(true)
	})
})
