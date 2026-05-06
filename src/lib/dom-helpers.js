/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { parseFileIdFromCurrentLocation } from './urls.js'

export const parseNumericFileId = (value) => {
	const id = Number(value)
	return Number.isFinite(id) && id > 0 ? id : null
}

export const parseFileIdFromRoute = () => {
	const fromPath = parseFileIdFromCurrentLocation()
	if (fromPath !== null) {
		return fromPath
	}
	const params = new URLSearchParams(window.location.search || '')
	return parseNumericFileId(params.get('fileid') || '')
}

export const isDarkMode = () => {
	const root = document.documentElement
	const body = document.body
	const classes = (root?.className || '') + ' ' + (body?.className || '')
	if (/theme[-_]?dark|dark-theme|theme--dark/i.test(classes)) {
		return true
	}
	const media = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)')
	return Boolean(media && media.matches)
}

export const isElementVisible = (element) => {
	if (!(element instanceof HTMLElement)) {
		return false
	}
	const style = window.getComputedStyle(element)
	if (style.display === 'none' || style.visibility === 'hidden') {
		return false
	}
	if (style.position === 'fixed') {
		return true
	}
	return element.offsetParent !== null
}
