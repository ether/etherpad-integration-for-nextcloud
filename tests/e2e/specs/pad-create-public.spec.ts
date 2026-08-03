/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	gotoFiles,
	closeViewer,
	createExternalPadFromTile,
	externalPadTileAvailable,
	createPublicPad,
	expectFileInList,
	expectExternalSnapshotViewerMounted,
	expectFilesRouteWithoutOpenFlag,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getFileViaDav } from '../fixtures/dav'

/**
 * Smoke flow #1 (issue #54): create an internal public pad via our
 * NewFileMenu entry + dialog, then confirm the native viewer mounts an
 * Etherpad iframe. Exercises the full plugin create path end-to-end:
 * dialog → POST create → frontmatter write → binding → viewer open.
 */
test.describe('public pad create + open', () => {
	const padName = uniquePadName('public-create')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates a public pad and opens it in the Etherpad viewer', async ({ page }) => {
		await gotoFiles(page)

		await createPublicPad(page, padName)

		// The file shows up in the listing.
		await expectFileInList(page, padName)

		// The tile is what makes this pad public. Without this assertion a
		// missing tile would silently produce a protected pad and every other
		// check here would still pass.
		expect(await getFileViaDav(padName)).toContain('access_mode: "public"')

		// Viewer mounts with an Etherpad iframe (not the no-viewer error template).
		await expectEtherpadViewerMounted(page)
	})
})

test.describe('existing public pad open', () => {
	const padName = uniquePadName('public-open-existing')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('opens an existing public pad from the file list', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)

		await closeViewer(page)
		await expectFilesRouteWithoutOpenFlag(page)
		await openPadFromFileList(page, padName)

		await expectEtherpadViewerMounted(page)
	})
})

/**
 * The "Public pad from URL" tile in Nextcloud's template picker. The tile
 * carries a template field, so the picker collects the address itself and the
 * create listener links the file with it — no half-finished file is stored.
 */
test.describe('external pad from the template picker', () => {
	const sourcePadName = uniquePadName('external-source')
	const externalPadName = uniquePadName('external-import')

	test.afterAll(async () => {
		await deleteViaDav(externalPadName)
		await deleteViaDav(sourcePadName)
	})

	test('asks for the pad address in the picker and opens the remote pad', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, sourcePadName)
		await expectEtherpadViewerMounted(page)
		const etherpadUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)

		// Only the tile's presence is a configuration question. Once it is
		// there, everything after it has to work — a file that never appears
		// would otherwise turn a listener or seeding regression into a skip.
		const tileOffered = await externalPadTileAvailable(page, externalPadName)
		test.skip(!tileOffered, 'External pads are disabled on this instance; external tile spec skipped.')
		await createExternalPadFromTile(page, etherpadUrl, externalPadName)

		// Nextcloud opens the file it just created, and a linked external pad
		// opens straight into the snapshot view — so only open it from the list
		// when that has not already happened.
		const alreadyOpen = await page.locator('.epnc-native-snapshot').first()
			.waitFor({ state: 'visible', timeout: 20_000 })
			.then(() => true)
			.catch(() => false)
		if (!alreadyOpen) {
			await openPadFromFileList(page, externalPadName)
		}
		await expectExternalSnapshotViewerMounted(page, etherpadUrl)
	})
})
