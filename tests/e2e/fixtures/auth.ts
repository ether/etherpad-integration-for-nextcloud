/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { expect, type Page } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { E2E } from './env'

const authDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '.auth')

/** Stored browser session for the primary account (E2E_USER). */
export const PRIMARY_STATE_FILE = resolve(authDir, 'state.json')

/** Stored browser session for the secondary account (E2E_USER2). */
export const SECONDARY_STATE_FILE = resolve(authDir, 'state-user2.json')

/**
 * Drive NC's standard web login form to authenticate the given browser
 * page as `user`. Used both by `auth.setup.ts` (primary account, run
 * once into a stored `storageState`) and by specs that need to attach a
 * second user's session inline (e.g. the user-share spec).
 *
 * The form is matched by a tolerant CSS / role lookup so it survives
 * NC's UI rewrites + custom social-login front doors that hide the
 * username/password inputs behind a "use a different login" link.
 */
export const fillLoginForm = async (page: Page, user: string, password: string): Promise<void> => {
	const directLoginLink = page.getByRole('link', { name: /username or email|benutzername oder e-mail/i })
	if (await directLoginLink.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await directLoginLink.click()
	}

	const userField = page.locator([
		'#user:visible',
		'input[name="user"]:visible',
		'input[name="username"]:visible',
		'input[name="email"]:visible',
		'input[type="email"]:visible',
	].join(', ')).first()
	const passwordField = page.locator([
		'#password:visible',
		'input[name="password"]:visible',
		'input[type="password"]:visible',
	].join(', ')).first()

	await userField.fill(user)
	await passwordField.fill(password)
	await page.getByRole('button', { name: /log in|login|sign in|anmelden|einloggen/i }).first().click()
}

/**
 * Dismiss Nextcloud's first-run wizard modal if it is showing. A freshly
 * created account sees the "A collaboration platform that puts you in
 * control" welcome modal on its first web session; it overlays the whole
 * UI and swallows clicks, so any spec acting as a brand-new user would
 * otherwise stall. Closing it once records the dismissal server-side, so
 * later sessions for the same user no longer show it.
 *
 * No-op when the modal is absent (the common case for an established
 * account), so it is cheap to call defensively after login.
 */
export const dismissFirstRunWizard = async (page: Page): Promise<void> => {
	const modal = page.locator('.modal-container, [role="dialog"]')
		.filter({ hasText: /puts you in control|collaboration platform|willkommen|kontrolle/i })
		.first()
	if (!(await modal.isVisible({ timeout: 3_000 }).catch(() => false))) {
		return
	}
	const close = modal.getByRole('button', { name: /close|schließen/i }).first()
	if (await close.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await close.click()
	} else {
		await page.keyboard.press('Escape')
	}
	await expect(modal).toBeHidden({ timeout: 10_000 })
}

/**
 * End-to-end login flow that lands `page` on the dashboard or files
 * view as the requested user. Reusable from any spec that needs an
 * authenticated context (e.g. opening a second browser context as user
 * B to verify share access).
 */
export const loginAs = async (page: Page, user: string, password: string): Promise<void> => {
	await page.goto(E2E.loginURL)
	await fillLoginForm(page, user, password)
	await page.waitForURL(/\/apps\/|\/index\.php\/apps\/|\/dashboard/, { timeout: 30_000 })
	await expect(page.locator('#user-menu, [data-cy-user-menu], .header-end').first()).toBeVisible({ timeout: 30_000 })
	// Brand-new accounts get the welcome modal here; dismiss it so the
	// stored session (and the server-side "seen" flag) start clean.
	await dismissFirstRunWizard(page)
}

/**
 * Log the page's user out of Nextcloud, the way the header menu does.
 *
 * Driven by URL rather than by clicking through the avatar menu: the menu
 * is markup that NC rewrites between versions, and a spec that fails
 * because a button moved says nothing about what it meant to test. The
 * request token is what makes this a real logout — `/logout` without one
 * is rejected as a forgery, which would leave the session standing and
 * the assertion afterwards passing for the wrong reason.
 *
 * The page must already be on a Nextcloud page; the token is rendered
 * into `<head>` of every one of them.
 */
export const logout = async (page: Page): Promise<void> => {
	const token = await page.evaluate(() => document.head.getAttribute('data-requesttoken') ?? '')
	if (token === '') {
		throw new Error('No data-requesttoken in <head>; is the page on a logged-in Nextcloud page?')
	}

	await page.goto(`${E2E.baseURL}/logout?requesttoken=${encodeURIComponent(token)}`)
	await page.waitForURL(/\/login/, { timeout: 30_000 })
}
