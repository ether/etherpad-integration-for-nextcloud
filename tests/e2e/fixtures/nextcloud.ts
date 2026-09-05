/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { expect, type Page } from '@playwright/test'
import { E2E } from './env'
import { buildFixtureName, type FixtureExtension } from './fixture-name'
import { runId } from './run-id'

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
	// An empty view is a legitimate landing state here — the revoke step
	// navigates back to check the row is gone. Nextcloud 31 renders the
	// empty-state note *instead of* the list container, so waiting only for
	// the list hangs until timeout on a view that loaded perfectly well.
	// `:visible` on every branch: the empty-state markup exists in the DOM
	// while the list is still loading, so an unfiltered `.first()` can latch
	// onto a hidden node and wait for it forever.
	await expect(
		page.locator([
			'[data-cy-files-list]:visible',
			'#app-content-files:visible',
			'.files-list:visible',
			'.empty-content:visible',
			'[data-cy-files-content-empty]:visible',
		].join(', ')).first(),
	).toBeVisible({ timeout: 30_000 })
	//
	// This waits for the view's shell, not for its data: Nextcloud can show
	// an empty state while it is still fetching rows, and a list that has
	// not rendered yet looks exactly like one a row was removed from. So a
	// caller must assert positively — expectFileInList polls for a row to
	// appear, and anything asserting that something is *gone* should say so
	// through the share API, which does not depend on rendering.
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

/**
 * Run the admin Etherpad health check and assert the configured pad server
 * responds.
 *
 * Asserted per row, not on the summary. The summary also folds in the
 * base-URL reachability row, which BaseUrlReachabilityCheck deliberately
 * reports as a *warning* rather than an error: an instance may be unable
 * to reach its own public URL — split-horizon DNS, an egress rule, or
 * Nextcloud's own block on requests into its own network — while users'
 * browsers reach it fine. The container stack is exactly such an instance,
 * and on a loaded CI runner it has reported that block. Gating the suite
 * on the summary therefore made an advisory row decide whether the run
 * passed; the nine unit tests in BaseUrlReachabilityCheckTest are what pin
 * that row's behaviour.
 *
 * What this asserts is what "the pad server responds" actually means: the
 * API host was contacted and the key was accepted. A real failure there
 * throws before any row is rendered and turns the summary into an error,
 * which is still refused below.
 *
 * The row it reads is named exactly, because `.first()` over
 * `etherpad_api_host, etherpad_host` did not read the row it was named
 * after: the template renders the base-URL slot above the API-URL one, so
 * DOM order always resolved it to the base URL. And when the API URL is
 * left empty both checks share the `etherpad_host` slot, where the
 * renderer lets the more severe verdict win — so that slot can never
 * stand in for "the API is reachable" either.
 */
export const runAdminEtherpadHealthCheck = async (page: Page): Promise<void> => {
	const status = page.locator('#etherpad-nextcloud-connection-status')
	await page.locator('#etherpad-nextcloud-health-check').click()

	await expect(status).toHaveClass(/ep-status-(success|warning)/, { timeout: 30_000 })
	await expect(status).not.toHaveClass(/ep-status-error/)
	// The API key row, and only it. It is the one slot a single check ever
	// writes to, and reaching "accepted" means the API host answered and
	// took the credentials — everything this helper claims.
	await expect(page.locator('[data-check-result="etherpad_api_key"]'))
		.toHaveClass(/ep-check-ok/, { timeout: 30_000 })
	// The base URL row has to be rendered, whatever it says: a check that
	// silently produced no row would otherwise pass everything above.
	await expect(page.locator('[data-check-result="etherpad_host"]'))
		.toHaveClass(/ep-check-(ok|warning)/, { timeout: 30_000 })
}

/**
 * The label of our template tile. It is the marker file's name, so it stays
 * the same in every language — a template is a file, and files have names,
 * not translations.
 */
const PUBLIC_PAD_TEMPLATE = 'Public pad'

/** Click the Files "+ New" toolbar button and wait for its menu. */
const openNewMenu = async (page: Page): Promise<void> => {
	await page.locator('[data-cy-upload-picker] button, .upload-picker button').first().click()
	await expect(page.getByRole('menu')).toBeVisible()
}

/**
 * Create a public pad. The type is chosen in Nextcloud's own template picker,
 * so this goes through the same flow as any other template — only the tile
 * differs. Returns the final file name used.
 */
export const createPublicPad = async (page: Page, fileName: string): Promise<string> => {
	return createPadFromTemplate(page, PUBLIC_PAD_TEMPLATE, fileName)
}

/**
 * Create a pad from the "Public pad from URL" tile: Nextcloud's picker asks
 * for the pad's address through the tile's template field, and the create
 * listener links the file with it.
 *
 * Whether external pads are configured is read from the environment, not from
 * the page — a missing tile is a regression here, and every step below has to
 * succeed.
 */
export const createExternalPadFromTile = async (page: Page, padUrl: string, fileName: string): Promise<string> => {
	await openNewMenu(page)
	await page.getByRole('menuitem', { name: /new pad|neues pad/i }).first().click()

	const fileNameInput = page.locator('input[type="text"]:visible').last()
	await fileNameInput.fill(fileName.replace(/\.pad$/i, ''))
	await page.getByRole('button', { name: /^(create|erstellen)$/i }).last().click()

	const tile = page.getByRole('dialog').getByText('Public pad from URL', { exact: true }).first()
	await expect(tile).toBeVisible({ timeout: 15_000 })
	await tile.click()
	// The picker confirms with an <input type="submit">, not a button, so match
	// the control itself: a by-label match also finds the "+ New" menu entries
	// still in the page behind the modal.
	await page.locator('.templates-picker__buttons input[type="submit"]').click()

	// Nextcloud's field modal, rendered from the tile's template field.
	const urlInput = page.locator('input[type="text"]:visible').last()
	await expect(urlInput).toBeVisible({ timeout: 15_000 })
	await urlInput.fill(padUrl)
	// Its accessible name comes from an aria-label ("Submit button"), not from
	// the visible, translated caption — so match either, unanchored.
	await page.getByRole('button', { name: /submit|übermitteln/i }).last().click()

	await expectFileInList(page, fileName)
	return fileName
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
	// tile labelled with our template's (extension-stripped) file name, then
	// confirm. The tile is not a button, so match by text — but only inside
	// the dialog and only on an exact label: the "+ New" menu is still in the
	// page behind it, and its "Public pad from URL" entry contains the label
	// of the "Public pad" tile.
	const tileLabel = templateLabel.replace(/\.pad$/i, '')
	const tile = page.getByRole('dialog').getByText(tileLabel, { exact: true }).first()
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

	// Nextcloud shows the template picker only when there is something to pick;
	// with no templates on the instance it creates the file straight away. So
	// race the two outcomes instead of waiting out a picker that will never
	// come — the wait would cost every run those seconds for nothing.
	//
	// The confirm control is an <input type="submit">; matching it by label
	// would also hit the "+ New" menu entries still in the page behind it.
	const confirm = page.locator('.templates-picker__buttons input[type="submit"]')
	const row = page.locator(`[data-cy-files-list-row-name="${fileName}"], [title="${fileName}"]`).first()
	const pickerShown = await Promise.race([
		confirm.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true),
		row.waitFor({ state: 'visible', timeout: 30_000 }).then(() => false),
	]).catch(() => false)
	if (pickerShown) {
		await confirm.click()
	}

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

/**
 * Open a pad by clicking its row, and make sure the click landed.
 *
 * Nextcloud's file list renders lazily — it says so itself in the table's
 * description — so a row can be resolved and then replaced underneath the
 * click, which then hits nothing. The result is a list still on screen and
 * a viewer that never opens, and the failure surfaces thirty seconds later
 * in whatever waits for the viewer, pointing at the viewer rather than at
 * the click that never happened.
 *
 * The retry is on the interaction, not on the assertion: a viewer that
 * fails to mount for any other reason still fails, in the caller, with its
 * full timeout.
 */
export const openPadFromFileList = async (page: Page, fileName: string): Promise<void> => {
	await expectFileInList(page, fileName)
	const row = page.locator(`[data-cy-files-list-row-name="${fileName}"], [title="${fileName}"]`).first()
	const viewer = page.locator('.viewer__content, .viewer, [data-cy-viewer]').first()

	for (let attempt = 0; attempt < 3; attempt++) {
		await row.click()
		try {
			await viewer.waitFor({ state: 'visible', timeout: 5_000 })
			return
		} catch {
			// The click did not take. Leave the verdict to the caller.
		}
	}
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

/**
 * The viewer a read-only share is supposed to get: the pad's content,
 * loaded from the pad server, and no Etherpad frame at all.
 *
 * The absence is the point. Nextcloud's read-only share stops at the file;
 * the pad lives on another host, so anything that hands over a pad URL or
 * a session hands over the ability to edit, whatever the share said.
 */
export const expectReadOnlyPadViewerMounted = async (page: Page, expectedText = ''): Promise<void> => {
	await expect(page.locator('.epnc-pad-doc').first()).toBeVisible({ timeout: 30_000 })
	// The surface carries no heading any more, so the refresh control is
	// what identifies it — and it is the one affordance the view has.
	await expect(page.getByRole('button', { name: /refresh|aktualisieren/i })).toBeVisible()
	// The document styles live in their own stylesheet, shared with the
	// embed. If it stops being loaded the view still works and still passes
	// every other assertion here, so the reading width is checked instead.
	await expect(page.locator('.epnc-pad-doc__text').first()).toHaveCSS('font-size', '15px')
	if (expectedText !== '') {
		// Without this the assertion passes just as well against the
		// loading state and the "still empty" hint, which render in the
		// same container.
		await expect(page.locator('.epnc-pad-doc').first()).toContainText(expectedText, { timeout: 30_000 })
	}
	await expect(
		page.locator('iframe[title="Etherpad"]'),
		'a read-only share must not be given a pad to type into',
	).toHaveCount(0)
}

export const expectExternalPadViewerMounted = async (page: Page, expectedOriginalUrl = ''): Promise<void> => {
	await expect(page.locator('.epnc-pad-doc').first()).toBeVisible({ timeout: 30_000 })
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

/**
 * Tick the recovery card's opt-in, then follow "Open the original". The
 * marker is written before the navigation, so a later open of the same
 * copy skips the card entirely.
 */
export const rememberAndFollowOpenTheOriginal = async (page: Page, expectedOriginalFileId: number): Promise<void> => {
	const box = page.getByRole('checkbox', { name: /always open the original from this file|aus dieser datei immer das original öffnen/i }).first()
	await expect(box).toBeVisible({ timeout: 30_000 })
	await box.check()

	// Waits for the navigation itself rather than going through
	// followOpenTheOriginal: with the box ticked the click first writes the
	// marker and only then leaves the page, so asserting on the viewer
	// before the URL would be reading the page we are still on.
	const link = page.getByRole('link', { name: /open the original \.pad file|urspr.ngliche \.pad-datei öffnen/i }).first()
	await expect(link).toBeVisible({ timeout: 30_000 })
	await Promise.all([
		page.waitForURL(new RegExp(`(/files/${expectedOriginalFileId}\\b|fileid=${expectedOriginalFileId}\\b)`), { timeout: 30_000 }),
		link.click(),
	])
	await expectEtherpadViewerMounted(page)
}

/** A unique-ish file name so parallel/repeat runs don't collide. */
export const uniquePadName = (label: string): string => uniqueName(label, 'pad')

/**
 * Every file and folder a spec creates goes through here: the name
 * carries this run's id, which is what lets the trash sweep in
 * global-teardown tell its own leftovers from a concurrent run's.
 */
export const uniqueName = (label: string, extension?: FixtureExtension): string =>
	buildFixtureName(label, { runId: runId(), extension })
