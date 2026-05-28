/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test as setup, expect, type Page } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdirSync } from 'node:fs'
import { E2E } from './fixtures/env'

const here = dirname(fileURLToPath(import.meta.url))
const stateFile = resolve(here, '.auth/state.json')

const fillLoginForm = async (page: Page): Promise<void> => {
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

	await userField.fill(E2E.user)
	await passwordField.fill(E2E.password)
	await page.getByRole('button', { name: /log in|login|sign in|anmelden|einloggen/i }).first().click()
}

/**
 * Logs into Nextcloud once via the standard login form and persists the
 * authenticated browser context to .auth/state.json. Every test project
 * reuses that storageState, so individual specs never re-login.
 *
 * The password is read from tests/e2e/.env.e2e (gitignored) — it is the
 * maintainer's own test-account credential, never committed.
 */
setup('authenticate', async ({ page }) => {
	mkdirSync(dirname(stateFile), { recursive: true })

	await page.goto(E2E.loginURL)

	await fillLoginForm(page)

	// A successful login lands on the dashboard or files; the user menu /
	// avatar in the top bar is a reliable "logged in" marker across NC apps.
	await page.waitForURL(/\/apps\/|\/index\.php\/apps\/|\/dashboard/, { timeout: 30_000 })
	await expect(page.locator('#user-menu, [data-cy-user-menu], .header-end').first()).toBeVisible({ timeout: 30_000 })

	await page.context().storageState({ path: stateFile })
})
