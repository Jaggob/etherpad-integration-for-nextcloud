/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { test, expect } from '@playwright/test'
import {
	gotoFiles,
	closeViewer,
	createPublicPad,
	expectFileInList,
	expectEtherpadViewerMounted,
	openPadFromFileList,
	uniquePadName,
} from '../fixtures/nextcloud'
import { deleteViaDav } from '../fixtures/dav'

/**
 * Smoke flow #1 (issue #54): create an internal public pad via our
 * NewFileMenu entry + dialog, then confirm the native viewer mounts an
 * Etherpad iframe. Exercises the full plugin create path end-to-end:
 * dialog → POST create → frontmatter write → binding → viewer open.
 */
test.describe('public pad create + open', () => {
	const padName = uniquePadName('public-create')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('creates a public pad and opens it in the Etherpad viewer', async ({ page }) => {
		await gotoFiles(page)

		await createPublicPad(page, padName)

		// The file shows up in the listing.
		await expectFileInList(page, padName)

		// Viewer mounts with an Etherpad iframe (not the no-viewer error template).
		await expectEtherpadViewerMounted(page)
	})
})

test.describe('existing public pad open', () => {
	const padName = uniquePadName('public-open-existing')

	test.afterAll(async () => {
		await deleteViaDav(padName)
	})

	test('opens an existing public pad from the file list', async ({ page }) => {
		await gotoFiles(page)
		await createPublicPad(page, padName)
		await expectEtherpadViewerMounted(page)

		await closeViewer(page)
		await openPadFromFileList(page, padName)

		await expectEtherpadViewerMounted(page)
	})
})
