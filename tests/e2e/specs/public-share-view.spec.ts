/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import { E2E } from '../fixtures/env'
import {
	gotoFiles,
	createPublicPad,
	expectEtherpadViewerMounted,
	uniquePadName,
} from '../fixtures/nextcloud'
import {
	createPublicReadShare,
	deletePublicShare,
	deleteViaDav,
} from '../fixtures/dav'

test.describe('public share access without login', () => {
	const padName = uniquePadName('public-share')
	let shareToken = ''
	let shareUrl = ''

	test.afterAll(async () => {
		await deletePublicShare(shareToken)
		await deleteViaDav(padName)
	})

	test('opens a shared public pad without authenticated storage state', async ({ page, browser }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)

		const share = await createPublicReadShare(padName)
		shareToken = share.token
		shareUrl = share.url

		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(shareUrl)
			await expect(publicPage.locator('.viewer__content, .viewer, [data-cy-viewer]').first()).toBeVisible({ timeout: 30_000 })
			await expect(publicPage.locator('iframe').first()).toBeVisible({ timeout: 30_000 })
		} finally {
			await publicContext.close()
		}
	})

	test('does not expose internal viewer data without login', async ({ browser }) => {
		const publicContext = await browser.newContext()
		const publicPage = await publicContext.newPage()
		try {
			await publicPage.goto(`${E2E.baseURL}/apps/etherpad_nextcloud/by-id/1`)

			await expect(publicPage.locator('iframe[title="Etherpad"], .epnc-viewer__iframe')).toHaveCount(0)
			await expect(publicPage.getByRole('heading', { name: /could not open pad|pad konnte nicht geöffnet werden/i })).toBeVisible()
		} finally {
			await publicContext.close()
		}
	})
})
