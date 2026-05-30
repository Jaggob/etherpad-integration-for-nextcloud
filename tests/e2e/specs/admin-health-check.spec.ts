/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test } from '@playwright/test'
import { gotoAdminPadSettings, runAdminEtherpadHealthCheck } from '../fixtures/nextcloud'

/**
 * Smoke flow #4 (issue #54): verify that the saved admin settings can
 * reach Etherpad. This catches broken API keys, wrong Etherpad URLs and
 * server-side health-check regressions with the same browser flow an
 * administrator uses.
 */
test.describe('admin Etherpad health check', () => {
	test('tests the configured Etherpad connection', async ({ page }) => {
		await gotoAdminPadSettings(page)
		await runAdminEtherpadHealthCheck(page)
	})
})
