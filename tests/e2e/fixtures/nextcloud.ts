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
 * Assert that the Etherpad viewer mounted: NC's viewer modal is present
 * and our viewer surfaced an Etherpad iframe (not the error/no-viewer
 * template).
 */
export const expectEtherpadViewerMounted = async (page: Page): Promise<void> => {
	const modal = page.locator('.viewer__content, .viewer, [data-cy-viewer]')
	await expect(modal.first()).toBeVisible({ timeout: 30_000 })
	await expect(page.locator('iframe').first()).toBeVisible({ timeout: 30_000 })
}

/** A unique-ish file name so parallel/repeat runs don't collide. */
export const uniquePadName = (label: string): string =>
	`e2e-${label}-${Date.now()}.pad`
