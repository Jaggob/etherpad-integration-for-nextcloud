/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import {
	ocDavFileSource,
	ocEmitEvent,
} from '../lib/oc-compat.js'

const USE_NATIVE_VIEWER = true

export const openCreatedPadInViewer = async (navigation, options = {}) => {
	if (hasNativeViewer() && navigation.path) {
		try {
			const fileId = Number(navigation.fileId)
			if (isFilesAppRoute() && Number.isFinite(fileId) && fileId > 0) {
				pushViewerRouteForCreatedPad(fileId, navigation.path, options.resolveOpenDir)
				await waitForRouteSettle()
			}
			await notifyViewerAboutCreatedFile(navigation.path)
			window.OCA.Viewer.open({
				path: navigation.path,
				onClose: clearFilesViewerRoute,
			})
			return
		} catch (e) {
			// Fall through to the route-based opener below.
		}
	}

	if (typeof options.fallbackOpen === 'function') {
		await options.fallbackOpen(navigation)
	}
}

const hasNativeViewer = () => USE_NATIVE_VIEWER && Boolean(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.open === 'function')

const isFilesAppRoute = () => (window.location.pathname || '').includes('/apps/files')

const waitForRouteSettle = () => new Promise((resolve) => {
	window.setTimeout(resolve, 120)
})

const notifyViewerAboutCreatedFile = async (path) => {
	const source = ocDavFileSource(path)
	if (source === '') {
		return
	}

	ocEmitEvent('editor:file:created', source)
	await waitForCreatedNodeRegistration()
}

const waitForCreatedNodeRegistration = () => new Promise((resolve) => {
	window.setTimeout(resolve, 900)
})

const pushViewerRouteForCreatedPad = (fileId, path, resolveOpenDir) => {
	const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
	if (!router || typeof router.goToRoute !== 'function') {
		return
	}
	router.goToRoute(
		null,
		{
			...(router.params || {}),
			view: (router.params && router.params.view) ? router.params.view : 'files',
			fileid: String(fileId),
		},
		{
			...(router.query || {}),
			dir: resolveOpenDirForCreatedPad(path, resolveOpenDir),
			editing: 'false',
			openfile: 'true',
		},
		true
	)
}

const resolveOpenDirForCreatedPad = (path, resolveOpenDir) => {
	if (typeof resolveOpenDir === 'function') {
		return resolveOpenDir(path)
	}
	const value = String(path || '')
	const slash = value.lastIndexOf('/')
	return slash > 0 ? value.substring(0, slash) : '/'
}

const clearFilesViewerRoute = () => {
	const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
	if (!router || typeof router.goToRoute !== 'function') {
		return
	}
	const query = { ...(router.query || {}) }
	delete query.openfile
	delete query.editing
	router.goToRoute(null, router.params || {}, query)
}
