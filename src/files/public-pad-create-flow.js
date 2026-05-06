/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID } from '../lib/constants.js'
import {
	apiCreatePadFromUrl,
	apiCreatePublicPad,
} from '../lib/api-client.js'
import {
	getCurrentDir,
	normalizeFilePath,
	resolveOpenDir,
} from '../lib/urls.js'
import { openCreatedPadInViewer } from './created-pad-opener.js'
import {
	openExternalPublicPadDialog,
	openInternalPublicPadDialog,
	openPublicPadModeDialog,
} from './pad-create-dialogs.js'

const ensurePadExtension = (name) => name.toLowerCase().endsWith('.pad') ? name : (name + '.pad')

const createdPadNavigation = (created, fallbackPath) => ({
	path: (created && typeof created.file === 'string') ? created.file : fallbackPath,
	fileId: created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null,
})

export const createPublicPadCreator = ({ openPadInNativeViewer }) => {
	const openCreatedPad = (created, fallbackPath) => openCreatedPadInViewer(
		createdPadNavigation(created, fallbackPath),
		{
			fallbackOpen: openPadInNativeViewer,
			resolveOpenDir,
		}
	)

	const createInternalPublicPad = async () => {
		const inputName = await openInternalPublicPadDialog()
		if (!inputName) {
			return
		}
		const name = inputName.trim()
		const filePath = normalizeFilePath(getCurrentDir(), ensurePadExtension(name))

		try {
			const created = await apiCreatePublicPad(filePath)
			await openCreatedPad(created, filePath)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not create public pad.')
			window.alert(message)
		}
	}

	const createExternalPublicPad = async () => {
		const values = await openExternalPublicPadDialog()
		if (!values) {
			return
		}
		const trimmedUrl = values.padUrl.trim()
		const name = values.name.trim()
		const filePath = normalizeFilePath(getCurrentDir(), ensurePadExtension(name))

		try {
			const created = await apiCreatePadFromUrl(filePath, trimmedUrl)
			await openCreatedPad(created, filePath)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not import public pad URL.')
			window.alert(message)
		}
	}

	return async () => {
		const choice = await openPublicPadModeDialog()
		if (choice === 'internal') {
			await createInternalPublicPad()
			return
		}
		if (choice === 'external') {
			await createExternalPublicPad()
		}
	}
}
