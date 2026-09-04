/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	gotoFiles,
	closeViewer,
	createExternalPadFromTile,
	createPublicPad,
	expectFileInList,
	expectExternalPadViewerMounted,
	expectFilesRouteWithoutOpenFlag,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniqueName,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getFileViaDav } from '../fixtures/dav'
import { E2E } from '../fixtures/env'

/**
 * Smoke flow #1 (issue #54): create an internal public pad through the tile in
 * Nextcloud's template picker, then confirm the native viewer mounts an
 * Etherpad iframe. Exercises the full create path end-to-end: picker → create
 * event → frontmatter write → binding → viewer open.
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
		// Configuration is declared, not guessed: inferring it from the tile
		// would let a broken provider or registration pass as a skip.
		test.skip(
			!E2E.externalPadsEnabled,
			'Set E2E_EXTERNAL_PADS=1 once the instance has allow_external_pads=yes and an allowlisted Etherpad host.',
		)
		await gotoFiles(page)
		await createPublicPad(page, sourcePadName)
		await expectEtherpadViewerMounted(page)
		const etherpadUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)

		await createExternalPadFromTile(page, etherpadUrl, externalPadName)

		// Nextcloud opens the file it just created, and a linked external pad
		// opens straight into the snapshot view — so only open it from the list
		// when that has not already happened.
		const alreadyOpen = await page.locator('.epnc-pad-doc').first()
			.waitFor({ state: 'visible', timeout: 20_000 })
			.then(() => true)
			.catch(() => false)
		if (!alreadyOpen) {
			await openPadFromFileList(page, externalPadName)
		}
		await expectExternalPadViewerMounted(page, etherpadUrl)
	})
})

/**
 * A pad whose name contains a `+`. The name survived neither the PHP
 * normalizer (urldecode turned `+` into a space, and the rule trimming
 * spaces before the extension finished the job) nor the browser's own
 * query parsing (URLSearchParams applies form-encoding rules). `C++.pad`
 * created and opened `C.pad` — a different file, silently.
 *
 * Checked against the pre-fix normalizer: the path-based reopen below
 * fails there, because the file the server looked for no longer existed.
 * The case where a viewer mounts the *wrong* pad rather than none needs
 * two files whose names differ in that one character, which is what the
 * public folder share in public-share-view.spec.ts sets up.
 */
test.describe('pad name containing a plus sign', () => {
	const padName = uniqueName('c++', 'pad')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates and reopens the pad it was asked for', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)

		// The exact name, not a shortened one.
		await expectFileInList(page, padName)
		await expectEtherpadViewerMounted(page)
		const padUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)

		// And opening it *by path* — the route that reads the name from a
		// query parameter, where the `+` used to be lost — reaches the same
		// pad. Asserting that some viewer mounted is not enough: the bug
		// opened a *different* pad, which mounts just as happily.
		await page.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/?file=${encodeURIComponent('/' + padName)}`)
		await expectEtherpadViewerMounted(page)
		expect(await readEtherpadUrlFromViewer(page)).toBe(padUrl)
	})
})
