/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createPadOpener } from '../../../src/files/pad-opener.js'

const installFilesRouter = () => {
	const router = {
		params: {},
		query: {},
		goToRoute: vi.fn((route, params = {}, query = {}) => {
			router.params = { ...params }
			router.query = { ...query }
		}),
	}
	window.OCP = { Files: { Router: router } }
	return router
}

beforeEach(() => {
	vi.useFakeTimers()
	window.history.replaceState({}, '', '/index.php/apps/files/files?dir=/Current')
	window.OCA = {
		Viewer: {
			open: vi.fn(),
		},
	}
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete window.OCA
	delete window.OCP
})

describe('pad opener', () => {
	it('opens Files-route pads through the native viewer and clears openfile on close', async () => {
		const router = installFilesRouter()
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await vi.advanceTimersByTimeAsync(120)

		expect(router.goToRoute).toHaveBeenCalledWith(
			null,
			{ view: 'files', fileid: '42' },
			{ dir: '/Folder', editing: 'false', openfile: 'true' }
		)
		expect(window.OCA.Viewer.open).toHaveBeenCalledWith(expect.objectContaining({
			path: '/Folder/Test.pad',
			onClose: expect.any(Function),
		}))

		const openOptions = window.OCA.Viewer.open.mock.calls[0][0]
		router.query = { dir: '/Folder', editing: 'false', openfile: 'true' }
		openOptions.onClose()

		expect(router.goToRoute).toHaveBeenLastCalledWith(
			null,
			router.params,
			{ dir: '/Folder' }
		)
	})

	it('deduplicates repeated open requests in a short window', async () => {
		const router = installFilesRouter()
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })
		await openPad({ path: '/Folder/Test.pad', fileId: 42 })

		expect(router.goToRoute).toHaveBeenCalledTimes(1)
	})

	it('opens directly with the native viewer outside the Files app', async () => {
		window.history.replaceState({}, '', '/index.php/apps/etherpad_nextcloud/')
		const openPad = createPadOpener()

		await openPad({ path: '/Folder/Test.pad', fileId: 42 })

		expect(window.OCA.Viewer.open).toHaveBeenCalledWith({ path: '/Folder/Test.pad' })
	})
})
