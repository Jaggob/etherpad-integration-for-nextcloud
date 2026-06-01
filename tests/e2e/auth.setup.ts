/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test as setup } from '@playwright/test'
import { dirname } from 'node:path'
import { mkdirSync } from 'node:fs'
import { E2E } from './fixtures/env'
import { loginAs, PRIMARY_STATE_FILE, SECONDARY_STATE_FILE } from './fixtures/auth'

/**
 * Logs into Nextcloud once via the standard login form and persists the
 * authenticated browser context to .auth/state.json. Every test project
 * reuses that storageState, so individual specs never re-login.
 *
 * The passwords are read from tests/e2e/.env.e2e (gitignored) — the
 * maintainer's own test-account credentials, never committed.
 */
setup('authenticate primary account', async ({ page }) => {
	mkdirSync(dirname(PRIMARY_STATE_FILE), { recursive: true })
	await loginAs(page, E2E.user, E2E.password)
	await page.context().storageState({ path: PRIMARY_STATE_FILE })
})

/**
 * Logs in the secondary account into its own stored session, so the
 * cross-user specs (e.g. user-to-user share) can attach it via
 * `storageState` instead of paying for a slow, flaky in-test form login.
 * Skips when no secondary browser account is configured.
 */
setup('authenticate secondary account', async ({ page }) => {
	setup.skip(
		!E2E.hasSecondaryBrowserAccount(),
		'E2E_USER2 / E2E_USER2_PASS not configured; secondary session not created.',
	)
	mkdirSync(dirname(SECONDARY_STATE_FILE), { recursive: true })
	await loginAs(page, E2E.secondaryUser!, E2E.secondaryPassword!)
	await page.context().storageState({ path: SECONDARY_STATE_FILE })
})
