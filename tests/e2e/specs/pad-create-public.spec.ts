/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Covers public and external pad creation, reopening, and filename preservation. */

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

test.describe('public pad create + open', () => {
	const padName = uniquePadName('public-create')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates a public pad and opens it in the Etherpad viewer', async ({ page }) => {
		await gotoFiles(page)

		await createPublicPad(page, padName)

		await expectFileInList(page, padName)

		// A missing tile would otherwise create a valid protected pad.
		expect(await getFileViaDav(padName)).toContain('access_mode: "public"')

		await expectEtherpadViewerMounted(page)

		const viewer = page.getByRole('dialog', { name: padName })
		await expect(viewer).toBeVisible()
		await viewer.getByRole('button', { name: /actions|aktionen/i }).click()
		await page.getByRole('menuitem', { name: /open sidebar|seitenleiste öffnen/i }).click()
		await expect(page.locator('aside.app-sidebar')).toBeVisible()
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

test.describe('external pad from the template picker', () => {
	const sourcePadName = uniquePadName('external-source')
	const externalPadName = uniquePadName('external-import')

	test.afterAll(async () => {
		await deleteViaDav(externalPadName)
		await deleteViaDav(sourcePadName)
	})

	test('asks for the pad address in the picker and opens the remote pad', async ({ page }) => {
		// Do not let a missing tile turn a configured test into a skip.
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

		// Nextcloud may already have opened the file it created.
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

test.describe('pad name containing a plus sign', () => {
	const padName = uniqueName('c++', 'pad')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates and reopens the pad it was asked for', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)

		await expectFileInList(page, padName)
		await expectEtherpadViewerMounted(page)
		const padUrl = await readEtherpadUrlFromViewer(page)
		await closeViewer(page)

		// The path-based route must preserve `+` rather than parse it as a space.
		await page.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/?file=${encodeURIComponent('/' + padName)}`)
		await expectEtherpadViewerMounted(page)
		expect(await readEtherpadUrlFromViewer(page)).toBe(padUrl)
	})
})
