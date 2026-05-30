/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { expect, type Page } from '@playwright/test'
import { E2E } from './env'

/**
 * Helpers for driving the Nextcloud Files app in E2E specs. Selectors
 * prefer stable hooks (NC `data-cy-*`, our own `data-testid`) over
 * localized text so specs survive language changes on the target
 * instance.
 */

/** Open the Files app at the user's root. */
export const gotoFiles = async (page: Page): Promise<void> => {
	await page.goto(`${E2E.baseURL}/apps/files/`)
	await expect(page.locator('[data-cy-files-list], #app-content-files, .files-list')).toBeVisible({ timeout: 30_000 })
}

/** Open this app's admin settings section. Requires an admin storage state. */
export const gotoAdminPadSettings = async (page: Page): Promise<void> => {
	await page.goto(`${E2E.baseURL}/settings/admin/etherpad_nextcloud_pads`)
	await expect(page.locator('#etherpad-nextcloud-admin-settings')).toBeVisible({ timeout: 30_000 })
}

/** Run the admin Etherpad health check and assert the configured pad server responds. */
export const runAdminEtherpadHealthCheck = async (page: Page): Promise<void> => {
	const status = page.locator('#etherpad-nextcloud-admin-status')
	await page.locator('#etherpad-nextcloud-health-check').click()

	await expect(status).toHaveClass(/ep-status-success/, { timeout: 30_000 })
	await expect(status).toContainText(/pad_count=|api=|latency=/, { timeout: 30_000 })
}

/** Click the Files "+ New" toolbar button and wait for its menu. */
const openNewMenu = async (page: Page): Promise<void> => {
	await page.locator('[data-cy-upload-picker] button, .upload-picker button').first().click()
	await expect(page.getByRole('menu')).toBeVisible()
}

/**
 * Create an internal public pad through our own "Public pad" NewFileMenu
 * entry + dialog. Returns the final file name used.
 */
export const createPublicPad = async (page: Page, fileName: string): Promise<string> => {
	await openNewMenu(page)
	// Menu entry label is localized; match our pad entries by their icon
	// menuitem text fallback. The internal entry is "Public pad".
	await page.getByRole('menuitem', { name: /public pad(?! from)|öffentliches pad(?! aus)/i }).first().click()

	await expect(page.getByText(/public pad|öffentliches pad/i).first()).toBeVisible()

	const input = page.locator('[data-testid="epnc-filename-input"], input[type="text"]:visible').last()
	await input.fill(fileName)
	await page.locator('[data-testid="epnc-create-submit"]').or(page.getByRole('button', { name: /create|erstellen/i })).first().click()

	// On success the dialog closes.
	await expect(page.getByText(/public pad|öffentliches pad/i).first()).toBeHidden({ timeout: 30_000 })
	return fileName
}

/**
 * Create an external public pad from an existing Etherpad URL. The dialog is
 * intentionally exercised through the UI because most regressions here happen
 * in the Files-app menu/dialog glue, not only in the backend API.
 */
export const createExternalPublicPadFromUrl = async (page: Page, padUrl: string, fileName: string): Promise<string> => {
	await openNewMenu(page)
	await page.getByRole('menuitem', { name: /public pad from url|öffentliches pad aus url/i }).first().click()

	await expect(page.getByText(/public pad from url|öffentliches pad aus url/i).first()).toBeVisible()

	const urlInput = page.locator('input[type="url"]:visible').last()
	await expect(urlInput).toBeVisible()
	await urlInput.fill(padUrl)
	await urlInput.press('Tab')
	await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A')
	await page.keyboard.type(fileName)
	await page.getByRole('button', { name: /create|erstellen/i }).last().click()

	await expect(urlInput).toBeHidden({ timeout: 30_000 })
	return fileName
}

export const createBlankPadFromTemplatePicker = async (page: Page, fileName: string): Promise<string> => {
	await openNewMenu(page)
	await page.getByRole('menuitem', { name: /new pad|neues pad/i }).first().click()

	const fileNameInput = page.locator('input[type="text"]:visible').last()
	await fileNameInput.fill(fileName.replace(/\.pad$/i, ''))

	await page.getByRole('button', { name: /create|erstellen/i }).last().click()
	await expectFileInList(page, fileName)
	return fileName
}

export const expectFileInList = async (page: Page, fileName: string): Promise<void> => {
	await expect(
		page.locator(`[data-cy-files-list-row-name="${fileName}"], [title="${fileName}"]`).first(),
	).toBeVisible({ timeout: 30_000 })
}

export const closeViewer = async (page: Page): Promise<void> => {
	const viewer = page.locator('.viewer__content, .viewer, [data-cy-viewer]').first()
	const closeButton = page.getByRole('button', { name: /close|schließen/i }).last()
	if (await closeButton.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await closeButton.click()
	} else {
		await page.keyboard.press('Escape')
	}
	await expect(viewer).toBeHidden({ timeout: 30_000 })
}

export const expectFilesRouteWithoutOpenFlag = async (page: Page): Promise<void> => {
	await expect.poll(() => page.url(), { timeout: 10_000 }).not.toMatch(/[?&]openfile=true\b/)
}

export const openPadFromFileList = async (page: Page, fileName: string): Promise<void> => {
	await expectFileInList(page, fileName)
	await page.locator(`[data-cy-files-list-row-name="${fileName}"], [title="${fileName}"]`).first().click()
}

/**
 * Assert that the Etherpad viewer mounted: NC's viewer modal is present
 * and our viewer surfaced an Etherpad iframe (not the error/no-viewer
 * template).
 */
export const expectEtherpadViewerMounted = async (page: Page): Promise<void> => {
	const modal = page.locator('.viewer__content, .viewer, [data-cy-viewer]')
	await expect(modal.first()).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('iframe').first()).toBeVisible({ timeout: 30_000 })
}

export const expectExternalSnapshotViewerMounted = async (page: Page, expectedOriginalUrl = ''): Promise<void> => {
	await expect(page.locator('.epnc-native-snapshot').first()).toBeVisible({ timeout: 30_000 })
	await expect(page.getByText(/pad from another server|pad von einem anderen server/i).first()).toBeVisible()
	const originalLink = page.getByRole('link', { name: /open original pad|original-pad öffnen/i })
	await expect(originalLink).toBeVisible()
	if (expectedOriginalUrl !== '') {
		await expect(originalLink).toHaveAttribute('href', expectedOriginalUrl)
	}
}

export const readEtherpadUrlFromViewer = async (page: Page): Promise<string> => {
	const frame = page.locator('iframe[title="Etherpad"]').first()
	await expect(frame).toBeVisible({ timeout: 30_000 })

	const src = await frame.getAttribute('src')
	if (src && /^https?:\/\//i.test(src)) {
		return src
	}

	const srcdoc = await frame.getAttribute('srcdoc')
	const match = srcdoc ? srcdoc.match(/<iframe\s+src="([^"]+)"/i) : null
	const encoded = match && match[1] ? match[1] : ''
	const decoded = encoded
		.replace(/&quot;/g, '"')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&amp;/g, '&')
	if (!/^https?:\/\//i.test(decoded)) {
		throw new Error('Could not read Etherpad URL from viewer iframe.')
	}
	return decoded
}

/**
 * Recovery card the viewer renders when the .pad file has no matching
 * binding row. The lookup against /find-original may resolve into one of
 * two states; both should surface a "Create new pad from this file"
 * action, and the original-found state additionally exposes "Open the
 * original .pad file" pointing back at the source.
 */
export const expectRecoveryCardForCopy = async (page: Page, options: { originalFound: boolean }): Promise<void> => {
	const card = page.locator('.epnc-native-error-message').first()
	await expect(card).toBeVisible({ timeout: 30_000 })
	await expect(page.getByRole('button', { name: /create new pad from this file|neues pad aus dieser datei erstellen/i }).first()).toBeVisible()
	if (options.originalFound) {
		await expect(page.getByRole('link', { name: /open the original \.pad file|urspr.ngliche \.pad-datei öffnen/i }).first()).toBeVisible()
	}
}

/** A unique-ish file name so parallel/repeat runs don't collide. */
export const uniquePadName = (label: string): string =>
	`e2e-${label}-${Date.now()}.pad`
