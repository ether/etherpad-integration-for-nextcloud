/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	gotoFiles,
	closeViewer,
	createExternalPublicPadFromUrl,
	createPublicPad,
	createPublicPadWithSuggestedName,
	expectFileInList,
	expectExternalSnapshotViewerMounted,
	expectFilesRouteWithoutOpenFlag,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	readEtherpadUrlFromViewer,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav } from '../fixtures/dav'

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

test.describe('external public pad create + snapshot viewer', () => {
	const sourcePadName = uniquePadName('external-source')
	const externalPadName = uniquePadName('external-import')

	test.afterAll(async () => {
		await deleteViaDav(externalPadName)
		await deleteViaDav(sourcePadName)
	})

	test('imports an Etherpad URL and opens the external snapshot viewer', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, sourcePadName)
		await expectEtherpadViewerMounted(page)
		const etherpadUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)

		const result = await createExternalPublicPadFromUrl(page, etherpadUrl, externalPadName)
		test.skip(
			!result.ok,
			`External pad import unavailable on this instance (${result.error ?? 'rejected'}); `
			+ 'needs allow_external_pads=yes and a resolvable, allowlisted Etherpad host.',
		)
		await expectExternalSnapshotViewerMounted(page, etherpadUrl)
	})
})

/**
 * Issue #180: creating several public pads in a row used to fail on the
 * second one, because the dialog always offered the same name and the
 * backend refuses to overwrite. The suggestion is derived from the folder
 * contents the NewFileMenu passes to our handler, so this also verifies that
 * Nextcloud actually hands them over — nothing unit tests can prove.
 */
test.describe('public pad names do not collide', () => {
	const created: string[] = []

	test.afterAll(async () => {
		for (const name of created) {
			await deleteViaDav(name)
		}
	})

	test('numbers the suggestion when the name is taken', async ({ page }) => {
		await gotoFiles(page)

		created.push(await createPublicPadWithSuggestedName(page))
		await closeViewer(page)
		await gotoFiles(page)
		created.push(await createPublicPadWithSuggestedName(page))

		expect(created[0]).not.toBe(created[1])
		for (const name of created) {
			await expectFileInList(page, name)
		}
	})
})
