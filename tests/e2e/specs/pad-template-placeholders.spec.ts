/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	createPadFromTemplate,
	expectEtherpadViewerMounted,
	gotoFiles,
	uniqueName,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav, getFileViaDav, mkcolViaDav, propfindFileId, putFileViaDav } from '../fixtures/dav'

/**
 * Template placeholder substitution end-to-end (#26). A `.pad` template in
 * the user's Templates folder whose body carries `{{date}}` / `{{user}}`
 * must, when picked from "+ New pad", produce a pad whose content has those
 * placeholders resolved (PadCreationService::materializeTemplateInto ->
 * PadPlaceholderResolver -> pushed into the new pad and written into the
 * target .pad).
 *
 * We craft the template .pad ourselves via WebDAV (placeholders live in the
 * snapshot body). The created file's frontmatter snapshot already holds the
 * resolved text, so a plain WebDAV GET is enough to assert substitution —
 * no Etherpad API or editor typing needed.
 */
test.describe('template placeholder substitution', () => {
	const ts = Date.now()
	const templateLabel = uniqueName('tmpl')
	const templatePath = `Templates/${templateLabel}.pad`
	const templatePadId = `e2e-tmpl-src-${ts}`
	const createdName = uniquePadName('tmpl-created')

	const buildTemplate = (fileId: number): string => [
		'---',
		'format: "etherpad-nextcloud/1"',
		`file_id: ${fileId}`,
		`pad_id: "${templatePadId}"`,
		'access_mode: "public"',
		'state: "active"',
		'deleted_at: null',
		'created_at: "2026-01-01T00:00:00+00:00"',
		'updated_at: "2026-01-01T00:00:00+00:00"',
		'snapshot_rev: 1',
		`pad_url: "https://pad.example.invalid/p/${templatePadId}"`,
		'---',
		'[TEXT]',
		'Heute ist {{date}} und Autor ist {{user}}.',
		'',
		'[HTML-BEGIN]',
		'<!DOCTYPE HTML><html><body>Heute ist {{date}} und Autor ist {{user}}.<br></body></html>',
		'[HTML-END]',
		'',
	].join('\n')

	test.afterAll(async () => {
		await deleteViaDav(createdName).catch(() => {})
		await deleteViaDav(templatePath).catch(() => {})
	})

	test('resolves {{date}} and {{user}} when creating from the template', async ({ page }) => {
		// Ensure the Templates folder exists (a fresh account's skeleton may
		// not include it), then place the template (two-step PUT so its
		// frontmatter carries the real, self-consistent file id).
		await mkcolViaDav('Templates')
		await putFileViaDav(templatePath, buildTemplate(1))
		const templateFileId = await propfindFileId(templatePath)
		await putFileViaDav(templatePath, buildTemplate(templateFileId))

		await gotoFiles(page)
		await createPadFromTemplate(page, templateLabel, createdName)
		await expectEtherpadViewerMounted(page)

		// The created .pad's snapshot must have the placeholders resolved.
		const created = await getFileViaDav(createdName)
		expect(created, 'literal {{date}} must be gone').not.toContain('{{date}}')
		expect(created, 'literal {{user}} must be gone').not.toContain('{{user}}')
		// {{date}} resolves to a real Y-m-d date.
		expect(created).toMatch(/Heute ist \d{4}-\d{2}-\d{2} und Autor ist .+\./)
	})
})
