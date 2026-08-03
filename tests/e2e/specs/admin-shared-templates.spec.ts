/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { expect, test } from '@playwright/test'
import {
	createPadFromTemplate,
	gotoAdminPadSettings,
	gotoFiles,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getFileViaDav } from '../fixtures/dav'

/**
 * The contract this feature exists for, end to end: an admin uploads a
 * template, everyone sees it as a tile, and creating from it yields a pad of
 * its own carrying the template's content.
 *
 * Neither half is visible to the other test levels — PHPUnit never renders the
 * settings page, and Vitest builds its own DOM around the script.
 */
test.describe('shared templates: upload, offer, create', () => {
	const templateName = uniquePadName('shared-template')
	const padName = uniquePadName('from-shared-template')
	const body = 'Agenda from the shared template'

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('offers an uploaded template as a tile and creates a pad from it', async ({ page }) => {
		const isAdmin = await gotoAdminPadSettings(page)
		test.skip(!isAdmin, 'E2E_USER is not a Nextcloud admin; shared-template spec skipped.')

		// A .pad file is frontmatter plus body. The pad id is the template's
		// own; creating from it must provision a fresh one rather than link
		// two files to the same pad.
		const template = [
			'---',
			'format: "etherpad-nextcloud/1"',
			'file_id: 1',
			'pad_id: "nc-shared-template-source"',
			'access_mode: "public"',
			'state: "active"',
			'deleted_at: null',
			'created_at: "2026-01-01T00:00:00+00:00"',
			'updated_at: "2026-01-01T00:00:00+00:00"',
			'snapshot_rev: 0',
			'---',
			'[TEXT]',
			body,
			'',
		].join('\n')

		try {
			// Inside the try from the first byte: the upload can succeed on the
			// server and still fail the assertion below, and the template would
			// then outlive the run.
			await page.locator('#epnc-template-file').setInputFiles({
				name: templateName,
				mimeType: 'application/x-etherpad-nextcloud',
				buffer: Buffer.from(template, 'utf8'),
			})
			await expect(page.locator('#epnc-template-list li', { hasText: templateName })).toBeVisible({ timeout: 30_000 })

			await gotoFiles(page)
			await createPadFromTemplate(page, templateName, padName)

			const created = await getFileViaDav(padName)
			expect(created).toContain(body)
			// Its own pad, not the template's: two files on one pad would edit
			// each other.
			expect(created).not.toContain('nc-shared-template-source')
			expect(created).toMatch(/pad_id: "[^"]+"/)
		} finally {
			// The template outlives the run otherwise, and the next one would
			// pick it up as an extra tile. Only remove what is actually there:
			// if the upload itself failed, waiting for its row would time out
			// and bury the real error under a cleanup one.
			const stillAdmin = await gotoAdminPadSettings(page)
			if (stillAdmin) {
				const row = page.locator('#epnc-template-list li', { hasText: templateName })
				// The list arrives over the API, so give it a moment to render
				// before concluding there is nothing to delete.
				const uploaded = await row.waitFor({ state: 'visible', timeout: 15_000 })
					.then(() => true)
					.catch(() => false)
				if (uploaded) {
					page.once('dialog', (dialog) => { void dialog.accept() })
					await row.locator('button').click()
					await expect(row).toHaveCount(0, { timeout: 30_000 })
				}
			}
		}
	})
})
