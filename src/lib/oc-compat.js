/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from './constants.js'

export const ocGenerateUrl = (path) => {
	if (window.OC && typeof window.OC.generateUrl === 'function') {
		return window.OC.generateUrl(path)
	}
	return '/index.php' + path
}

export const ocImagePath = (app, asset) => {
	if (window.OC && typeof window.OC.imagePath === 'function') {
		return window.OC.imagePath(app, asset)
	}
	return ''
}

export const ocRequestToken = (fallback = '') => {
	const configuredFallback = String(fallback || '').trim()
	if (configuredFallback !== '') {
		return configuredFallback
	}
	return String((window.OC && window.OC.requestToken) || '')
}

export const ocPermissionRead = () => {
	const value = window.OC && window.OC.PERMISSION_READ
	const numeric = Number(value)
	return Number.isFinite(numeric) && numeric > 0 ? numeric : 1
}

export const ocPermissionCreate = () => {
	const value = window.OC && window.OC.PERMISSION_CREATE
	const numeric = Number(value)
	return Number.isFinite(numeric) && numeric > 0 ? numeric : 4
}

export const nextcloudMajorVersion = () => {
	const candidates = [
		window.OC && window.OC.config && window.OC.config.version,
		window.OC && window.OC.config && window.OC.config.versionstring,
		window.oc_appconfig && window.oc_appconfig.core && window.oc_appconfig.core.version,
		document.documentElement && document.documentElement.getAttribute('data-version'),
	]
	for (const candidate of candidates) {
		const value = String(candidate || '').trim()
		if (value === '') {
			continue
		}
		const match = value.match(/^(\d+)/)
		if (!match) {
			continue
		}
		const parsed = parseInt(match[1], 10)
		if (Number.isFinite(parsed) && parsed > 0) {
			return parsed
		}
	}
	return null
}

export const translate = (text) => (typeof window.t === 'function' ? window.t(APP_ID, text) : text)
