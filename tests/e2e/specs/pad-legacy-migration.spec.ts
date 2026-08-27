/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	closeViewer,
	createPublicPad,
	expectEtherpadViewerMounted,
	gotoFiles,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniquePadName,
	uniqueName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getFileViaDav, putFileViaDav } from '../fixtures/dav'

/**
 * Legacy Ownpad migration: a `.pad` file still in the old
 * `[InternetShortcut]` format (an Ownpad bookmark, not our YAML
 * frontmatter) must be migrated in place the first time it is opened.
 *
 * Reproduced with no special fixtures, exactly as the maintainer
 * suggested: we just write a legacy shortcut file ourselves. To stay on
 * the same-server (managed) migration branch we point it at this
 * instance's own Etherpad origin (read off a throwaway pad we create
 * first) with a fresh, unbound pad id so the claim-collision guard does
 * not refuse it.
 *
 * Assertion: after opening, the viewer mounts the Etherpad pad and the
 * file on disk is no longer `[InternetShortcut]` but the migrated YAML
 * frontmatter (`PadFileService::parseLegacyOwnpadShortcut` -> migrate).
 */
test.describe('legacy Ownpad .pad migration', () => {
	const originProbe = uniquePadName('legacy-origin-probe')
	const legacyName = uniqueName('legacy', '.pad')
	const legacyPadId = `e2e-legacy-${Date.now()}`

	test.afterAll(async () => {
		await deleteViaDav(legacyName).catch(() => {})
		await deleteViaDav(originProbe).catch(() => {})
	})

	test('migrates an [InternetShortcut] .pad in place on first open', async ({ page }) => {
		// Learn this instance's Etherpad origin from a real pad so the legacy
		// URL lands on the same-server (managed) migration branch.
		await gotoFiles(page)
		await createPublicPad(page, originProbe)
		await expectEtherpadViewerMounted(page)
		const probeUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)
		const origin = new URL(probeUrl).origin

		// Write a legacy Ownpad shortcut pointing at a fresh, unbound pad id.
		const legacyContent = `[InternetShortcut]\nURL=${origin}/p/${legacyPadId}\n`
		await putFileViaDav(legacyName, legacyContent)

		// Sanity: the file really is in the legacy format before we open it.
		const before = await getFileViaDav(legacyName)
		expect(before).toContain('[InternetShortcut]')

		// Opening triggers the lazy migration + provisions the managed pad.
		await gotoFiles(page)
		await openPadFromFileList(page, legacyName)
		await expectEtherpadViewerMounted(page)
		await closeViewer(page)

		// The on-disk file is now migrated YAML, not the old shortcut.
		const after = await getFileViaDav(legacyName)
		expect(after).not.toContain('[InternetShortcut]')
		expect(after).toContain('format: "etherpad-nextcloud/1"')
		expect(after).toContain('pad_id:')
	})
})
