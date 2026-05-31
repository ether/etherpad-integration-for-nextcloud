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

/** Open the Files app at a specific directory (e.g. after moving a file into it). */
export const gotoFilesDir = async (page: Page, dir: string): Promise<void> => {
	const normalized = '/' + dir.replace(/^\/+|\/+$/g, '')
	await page.goto(`${E2E.baseURL}/apps/files/?dir=${encodeURIComponent(normalized)}`)
	await expect(page.locator('[data-cy-files-list], #app-content-files, .files-list')).toBeVisible({ timeout: 30_000 })
}

/** Open the "Shared with me" view — used by the user-share spec. */
export const gotoSharedWithMe = async (page: Page): Promise<void> => {
	await page.goto(`${E2E.baseURL}/apps/files/sharingin`)
	await expect(page.locator('[data-cy-files-list], #app-content-files, .files-list')).toBeVisible({ timeout: 30_000 })
}

/**
 * Open this app's admin settings section. Returns false (without throwing)
 * when the account is not an admin — NC then serves 403 / redirects and the
 * settings root never appears — so the caller can skip rather than time out.
 */
export const gotoAdminPadSettings = async (page: Page): Promise<boolean> => {
	await page.goto(`${E2E.baseURL}/settings/admin/etherpad_nextcloud_pads`)
	// waitFor (not isVisible({timeout}), whose timeout is a no-op) so a slow
	// or JS-mounted panel still resolves true for an admin; only a genuine
	// non-admin (403/redirect, panel never appears) returns false.
	return page.locator('#etherpad-nextcloud-admin-settings')
		.waitFor({ state: 'visible', timeout: 10_000 })
		.then(() => true)
		.catch(() => false)
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
 *
 * Returns `{ ok: true }` on success. When external pads are disabled or the
 * host is rejected, the dialog shows an inline error instead of closing;
 * we return `{ ok: false, error }` so the caller can skip rather than hang
 * until timeout. Filling the URL auto-suggests a file name (on blur), so we
 * set the name *after* the URL and target the field by test id rather than
 * relying on tab order.
 */
export const createExternalPublicPadFromUrl = async (
	page: Page,
	padUrl: string,
	fileName: string,
): Promise<{ ok: boolean, error?: string }> => {
	await openNewMenu(page)
	await page.getByRole('menuitem', { name: /public pad from url|öffentliches pad aus url/i }).first().click()

	const modal = page.locator('[data-epnc-modal="external"]')
	await expect(modal).toBeVisible()

	const urlInput = modal.locator('[data-testid="epnc-external-url-input"]')
	await urlInput.fill(padUrl)
	await urlInput.blur()

	// Set the name explicitly after the URL's blur-suggestion, by test id.
	await modal.locator('[data-testid="epnc-filename-input"]').fill(fileName)
	await modal.locator('[data-testid="epnc-create-submit"]').click()

	// Race success (dialog closes) against the inline error (feature off /
	// host rejected) so a disabled instance skips instead of timing out.
	const errorNode = modal.locator('[data-testid="epnc-modal-error"]')
	const result = await Promise.race([
		modal.waitFor({ state: 'hidden', timeout: 30_000 }).then(() => ({ ok: true as const })),
		errorNode.filter({ hasText: /\S/ }).waitFor({ state: 'visible', timeout: 30_000 })
			.then(async () => ({ ok: false as const, error: (await errorNode.textContent())?.trim() || 'rejected' })),
	])
	return result
}

/**
 * Create a pad from a SPECIFIC template via NC's template picker (as
 * opposed to the blank entry). `templateLabel` matches the tile NC
 * renders for the template file in the user's Templates folder.
 */
export const createPadFromTemplate = async (page: Page, templateLabel: string, fileName: string): Promise<string> => {
	await openNewMenu(page)
	await page.getByRole('menuitem', { name: /new pad|neues pad/i }).first().click()

	// Step 1 — the "New pad" dialog only asks for the file name.
	const fileNameInput = page.locator('input[type="text"]:visible').last()
	await fileNameInput.fill(fileName.replace(/\.pad$/i, ''))
	await page.getByRole('button', { name: /^(create|erstellen)$/i }).last().click()

	// Step 2 — the template chooser ("Choose a template for …"). Pick the
	// tile labelled with our template's (extension-stripped) file name,
	// then confirm. The tile may not be a button, so match by text and
	// click the enclosing option.
	const tileLabel = templateLabel.replace(/\.pad$/i, '')
	const tile = page.getByText(tileLabel, { exact: false }).first()
	await expect(tile).toBeVisible({ timeout: 15_000 })
	await tile.click()
	await page.getByRole('button', { name: /create|erstellen|anhand der ausgewählten vorlage/i }).last().click()

	await expectFileInList(page, fileName)
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
	// waitFor (not isVisible({timeout}), whose timeout is a documented no-op)
	// so a close button that paints slightly late is still clicked rather
	// than falling through to Escape; only a truly absent button uses Escape.
	const hasCloseButton = await closeButton
		.waitFor({ state: 'visible', timeout: 5_000 })
		.then(() => true)
		.catch(() => false)
	if (hasCloseButton) {
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

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/**
 * Open Etherpad's user list and assert the protected-pad session carries
 * the NC display name. This intentionally targets protected pads only:
 * public pads open without a personal Etherpad session so they do not
 * leak the viewer's name to the pad server.
 */
export const expectEtherpadCurrentUserName = async (page: Page, expectedName: string): Promise<void> => {
	const name = expectedName.trim()
	if (name === '') {
		throw new Error('Expected Etherpad display name must not be empty.')
	}
	const expected = new RegExp(escapeRegExp(name))

	// The NC viewer hosts a same-origin srcdoc wrapper which then embeds the
	// actual cross-origin Etherpad iframe one level deeper.
	const etherpad = page
		.frameLocator('iframe[title="Etherpad"]').first()
		.frameLocator('iframe[title="Etherpad"]').first()
	await expect(etherpad.locator('body')).toBeVisible({ timeout: 30_000 })

	const showUsers = etherpad.locator([
		'#showusers',
		'button:has(.buttonicon-showusers)',
		'.buttonicon-showusers',
		'[data-l10n-id="pad.toolbar.showusers"]',
		'[aria-label*="user" i]',
		'[title*="user" i]',
		'[aria-label*="benutzer" i]',
		'[title*="benutzer" i]',
	].join(', ')).first()
	await expect(showUsers).toBeVisible({ timeout: 30_000 })
	await showUsers.click()

	const currentUserNameInput = etherpad.locator([
		'#myusernameedit',
		'input[name="username"]',
		'input[id*="username" i]',
		'input[class*="username" i]',
	].join(', ')).first()
	// waitFor (not isVisible({timeout}), whose timeout is a documented no-op)
	// so a popup that renders the username input slightly late still takes
	// the precise toHaveValue branch. The current user's name lives in the
	// input value, which the toContainText fallback below cannot match.
	const hasUserNameInput = await currentUserNameInput
		.waitFor({ state: 'visible', timeout: 5_000 })
		.then(() => true)
		.catch(() => false)
	if (hasUserNameInput) {
		await expect(currentUserNameInput).toHaveValue(expected, { timeout: 15_000 })
		return
	}

	await expect(etherpad.locator([
		'#users',
		'#userlist',
		'.userlist',
		'[id*="users" i]',
		'[class*="userlist" i]',
	].join(', ')).first()).toContainText(expected, { timeout: 15_000 })
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

/**
 * Click the recovery card's "Open the original .pad file" affordance and
 * confirm it actually navigates to the original pad: the Etherpad viewer
 * mounts and the URL now points at the original file (by its id), not the
 * copy. `expectedOriginalFileId` is the original's NC file id.
 */
export const followOpenTheOriginal = async (page: Page, expectedOriginalFileId: number): Promise<void> => {
	const link = page.getByRole('link', { name: /open the original \.pad file|urspr.ngliche \.pad-datei öffnen/i }).first()
	await expect(link).toBeVisible({ timeout: 30_000 })
	await link.click()
	await expectEtherpadViewerMounted(page)
	// NC's viewer route carries the file id in the path (/files/<id>) or,
	// depending on version, as a fileid= query param — accept either.
	await expect.poll(() => page.url(), { timeout: 15_000 })
		.toMatch(new RegExp(`(/files/${expectedOriginalFileId}\\b|fileid=${expectedOriginalFileId}\\b)`))
}

/** A unique-ish file name so parallel/repeat runs don't collide. */
export const uniquePadName = (label: string): string =>
	`e2e-${label}-${Date.now()}.pad`
