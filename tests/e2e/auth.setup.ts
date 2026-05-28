/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test as setup, expect } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdirSync } from 'node:fs'
import { E2E } from './fixtures/env'

const here = dirname(fileURLToPath(import.meta.url))
const stateFile = resolve(here, '.auth/state.json')

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

	await page.goto(`${E2E.baseURL}/login`)

	await page.locator('#user').fill(E2E.user)
	await page.locator('#password').fill(E2E.password)
	await page.locator('button[type="submit"]').first().click()

	// A successful login lands on the dashboard or files; the user menu /
	// avatar in the top bar is a reliable "logged in" marker across NC apps.
	await page.waitForURL(/\/apps\/|\/index\.php\/apps\/|\/dashboard/, { timeout: 30_000 })
	await expect(page.locator('#user-menu, [data-cy-user-menu], .header-end')).toBeVisible({ timeout: 30_000 })

	await page.context().storageState({ path: stateFile })
})
