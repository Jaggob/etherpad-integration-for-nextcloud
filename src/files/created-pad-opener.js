/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { MIME } from '../lib/constants.js'
import {
	ocDavFileFetchUrl,
	ocDavFileSource,
	ocEmitEvent,
	ocPermissionRead,
	ocRequestToken,
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

	const registered = await registerCreatedFileNode(path)
	if (!registered) {
		ocEmitEvent('editor:file:created', source)
	}
	await waitForCreatedNodeRegistration()
}

const waitForCreatedNodeRegistration = () => new Promise((resolve) => {
	window.setTimeout(resolve, 500)
})

const registerCreatedFileNode = async (path) => {
	if (!hasNextcloudEventBus()) {
		return false
	}

	try {
		const node = await fetchCreatedFileNode(path)
		if (!node) {
			return false
		}
		return ocEmitEvent('files:node:created', node)
	} catch (e) {
		return false
	}
}

const hasNextcloudEventBus = () => Boolean(window._nc_event_bus || (window.OC && window.OC._eventBus))

const fetchCreatedFileNode = async (path) => {
	const fetchUrl = ocDavFileFetchUrl(path)
	const source = ocDavFileSource(path)
	if (fetchUrl === '' || source === '') {
		return null
	}

	const response = await fetch(fetchUrl, {
		method: 'PROPFIND',
		headers: {
			'Content-Type': 'application/xml; charset=utf-8',
			Depth: '0',
			requesttoken: ocRequestToken(),
		},
		credentials: 'same-origin',
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns"><d:prop><d:getcontentlength/><d:getcontenttype/><d:getetag/><d:getlastmodified/><d:creationdate/><d:displayname/><nc:has-preview/><oc:fileid/><oc:owner-id/><oc:owner-display-name/><oc:permissions/><oc:size/></d:prop></d:propfind>',
	})
	if (!response.ok) {
		return null
	}

	const documentXml = new DOMParser().parseFromString(await response.text(), 'application/xml')
	const prop = firstElementByLocalName(documentXml, 'prop')
	if (!prop) {
		return null
	}

	const fileId = Number(propText(prop, 'fileid'))
	if (!Number.isFinite(fileId) || fileId <= 0) {
		return null
	}

	const normalizedPath = String(path || '').trim()
	const basename = normalizedPath.split('/').filter(Boolean).pop() || normalizedPath
	const dirname = normalizedPath.includes('/')
		? (normalizedPath.substring(0, normalizedPath.lastIndexOf('/')) || '/')
		: '/'
	const mtimeValue = propText(prop, 'getlastmodified')
	const permissionsString = propText(prop, 'permissions')

	return {
		id: fileId,
		fileid: fileId,
		source,
		path: normalizedPath,
		basename,
		displayname: propText(prop, 'displayname') || basename,
		dirname,
		mime: propText(prop, 'getcontenttype') || MIME,
		size: Number(propText(prop, 'size') || propText(prop, 'getcontentlength') || 0),
		mtime: mtimeValue !== '' ? new Date(mtimeValue) : new Date(),
		owner: propText(prop, 'owner-id') || currentUserId(),
		root: source.substring(0, source.length - normalizedPath.length),
		permissions: parseDavPermissions(permissionsString),
		type: 'file',
		attributes: {
			etag: propText(prop, 'getetag'),
			fileid: fileId,
			permissions: permissionsString,
			'owner-id': propText(prop, 'owner-id'),
			'owner-display-name': propText(prop, 'owner-display-name'),
			'has-preview': propText(prop, 'has-preview'),
		},
	}
}

const firstElementByLocalName = (node, localName) => {
	const elements = node.getElementsByTagNameNS('*', localName)
	return elements && elements.length > 0 ? elements[0] : null
}

const propText = (prop, localName) => {
	const element = firstElementByLocalName(prop, localName)
	return element ? String(element.textContent || '').trim() : ''
}

const currentUserId = () => String((window.OC && window.OC.getCurrentUser && window.OC.getCurrentUser().uid) || '')

const parseDavPermissions = (permissions) => {
	let value = 0
	if (permissions.includes('G')) value |= ocPermissionRead()
	if (permissions.includes('W')) value |= 2
	if (permissions.includes('CK')) value |= 4
	if (permissions.includes('NV')) value |= 8
	if (permissions.includes('D')) value |= 16
	if (permissions.includes('R')) value |= 32
	return value
}

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
