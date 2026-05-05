/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from '../lib/constants.js'
import { nextcloudMajorVersion, ocPermissionCreate } from '../lib/oc-compat.js'

const PUBLIC_PAD_MENU_ENTRY_ID = APP_ID + '_public_pad'
const PUBLIC_PAD_MENU_ICON_CLASS = 'icon-filetype-etherpad-nextcloud-pad'
const PUBLIC_PAD_MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" fill-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="2" clip-rule="evenodd" viewBox="0 0 355 355"><path fill="#5ac395" d="M317 89v177c0 28-23 51-51 51H89c-28 0-51-23-51-51V89c0-28 23-51 51-51h177c28 0 51 23 51 51"/><path fill="#4aa57f" fill-rule="nonzero" d="M240 144q-4-3-8 0-6 6 0 9a36 36 0 0 1 0 52 6 6 0 0 0 3 12q3 0 5-3c19-18 19-50 0-70"/><path fill="#479a76" fill-rule="nonzero" d="M267 122q-4-5-9 0-4 4 0 10c25 26 25 68 0 93q-4 5 0 9 5 4 9 0c30-31 30-81 0-112"/><path fill="#fff" d="M192 130v2q-1 8-10 10H76q-9-2-11-10v-2q1-10 11-10h106q9 0 10 10m24 51v2q-1 11-12 11H78q-10 0-12-11v-2q2-10 12-11h126q12 1 12 11"/><path fill="#fff" d="M216 181v2q-1 11-12 11H78q-10 0-12-11v-2q2-10 12-11h126q12 1 12 11m-57 52v1q-1 11-10 11H76q-9 0-11-11v-1q1-10 11-11h73q9 1 10 11"/><path fill="#fff" d="M159 233v1q-1 11-10 11H76q-9 0-11-11v-1q1-10 11-11h73q9 1 10 11"/></svg>'
const PAD_MENU_ORDER = 98
const PUBLIC_PAD_MENU_REGISTRATION_MAX_ATTEMPTS = 120
const PUBLIC_PAD_MENU_REGISTRATION_RETRY_MS = 500

const supportsInlineNewFileMenuSvg = () => {
	const major = nextcloudMajorVersion()
	return major !== null && major >= 33
}

const isDuplicateError = (error) => {
	const message = (error && error.message) ? String(error.message) : ''
	return message.toLowerCase().includes('duplicate')
}

const extractCreateCapabilityFromMenuContext = (arg) => {
	if (!arg || typeof arg !== 'object') {
		return null
	}
	if (arg.hasCreatePermission === false) {
		return false
	}
	if (arg.hasCreatePermission === true) {
		return true
	}
	if (typeof arg.canCreate === 'boolean') {
		return arg.canCreate
	}
	const requiredPermission = ocPermissionCreate()
	const permissionCandidates = [
		arg.permissions,
		arg.permission,
		arg.attributes && arg.attributes.permissions,
		typeof arg.get === 'function' ? arg.get('permissions') : null,
	]
	for (const candidate of permissionCandidates) {
		const numeric = Number(candidate)
		if (Number.isFinite(numeric)) {
			return (numeric & requiredPermission) === requiredPermission
		}
	}
	return null
}

const canCreateFromMenuContext = (...args) => {
	for (const arg of args) {
		const resolved = extractCreateCapabilityFromMenuContext(arg)
		if (resolved !== null) {
			return resolved
		}
	}
	return true
}

const resolveNewFileMenuApi = () => {
	const roots = [
		window.OCP && window.OCP.Files,
		window.OCA && window.OCA.Files,
	]
	for (const root of roots) {
		if (!root || typeof root !== 'object') {
			continue
		}
		if (typeof root.addNewFileMenuEntry === 'function') {
			return {
				register: root.addNewFileMenuEntry.bind(root),
				unregister: (typeof root.removeNewFileMenuEntry === 'function') ? root.removeNewFileMenuEntry.bind(root) : null,
			}
		}
		if (typeof root.getNewFileMenu === 'function') {
			let menu = null
			try {
				menu = root.getNewFileMenu()
			} catch (error) {
				menu = null
			}
			if (menu && typeof menu.registerEntry === 'function') {
				return {
					register: menu.registerEntry.bind(menu),
					unregister: (typeof menu.unregisterEntry === 'function') ? menu.unregisterEntry.bind(menu) : null,
				}
			}
		}
	}
	const globalMenu = window._nc_newfilemenu
	if (globalMenu && typeof globalMenu === 'object' && typeof globalMenu.registerEntry === 'function') {
		return {
			register: globalMenu.registerEntry.bind(globalMenu),
			unregister: (typeof globalMenu.unregisterEntry === 'function') ? globalMenu.unregisterEntry.bind(globalMenu) : null,
		}
	}
	return null
}

export const createPublicPadMenuRegistrar = ({ isFilesAppRoute, onCreatePublicPad }) => {
	let publicPadMenuApiRegistered = false
	let publicPadMenuLegacyApiRegistered = false
	let publicPadMenuLegacyPluginHooked = false
	let publicPadMenuRegistrationToken = 0

	const publicPadMenuHandler = () => {
		void onCreatePublicPad()
	}

	const publicPadMenuEnabled = (...args) => canCreateFromMenuContext(...args)

	const buildPublicPadMenuEntry = () => ({
		id: PUBLIC_PAD_MENU_ENTRY_ID,
		displayName: t(APP_ID, 'Public pad'),
		...(supportsInlineNewFileMenuSvg()
			? { iconSvgInline: PUBLIC_PAD_MENU_ICON_SVG }
			: { iconClass: PUBLIC_PAD_MENU_ICON_CLASS }),
		order: PAD_MENU_ORDER,
		enabled: publicPadMenuEnabled,
		handler: publicPadMenuHandler,
	})

	const buildLegacyPublicPadMenuEntry = () => ({
		id: PUBLIC_PAD_MENU_ENTRY_ID,
		displayName: t(APP_ID, 'Public pad'),
		templateName: t(APP_ID, 'Public pad'),
		iconClass: PUBLIC_PAD_MENU_ICON_CLASS,
		...(supportsInlineNewFileMenuSvg() ? { iconSvgInline: PUBLIC_PAD_MENU_ICON_SVG } : {}),
		fileType: 'file',
		order: PAD_MENU_ORDER,
		actionHandler: publicPadMenuHandler,
	})

	const registerPublicPadEntryViaNewFileMenuApi = async () => {
		const api = resolveNewFileMenuApi()
		if (!api) {
			return false
		}

		const entry = buildPublicPadMenuEntry()
		try {
			api.register(entry)
			publicPadMenuApiRegistered = true
			return true
		} catch (error) {
			if (isDuplicateError(error)) {
				publicPadMenuApiRegistered = true
				return true
			}
			return false
		}
	}

	const registerPublicPadEntryViaLegacyPluginApi = () => {
		if (publicPadMenuLegacyApiRegistered) {
			return true
		}
		const legacyEntry = buildLegacyPublicPadMenuEntry()
		const tryDirectLegacyMenu = () => {
			const candidates = [
				window.OCA && window.OCA.Files && window.OCA.Files.NewFileMenu,
				window.OCA && window.OCA.Files && window.OCA.Files.newFileMenu,
			]
			for (const menu of candidates) {
				if (!menu || typeof menu !== 'object') {
					continue
				}
				if (typeof menu.addMenuEntry === 'function') {
					try {
						menu.addMenuEntry(legacyEntry)
						return true
					} catch (error) {
						if (isDuplicateError(error)) {
							return true
						}
					}
				}
				if (typeof menu.registerEntry === 'function') {
					try {
						menu.registerEntry(buildPublicPadMenuEntry())
						return true
					} catch (error) {
						if (isDuplicateError(error)) {
							return true
						}
					}
				}
			}
			return false
		}
		try {
			if (tryDirectLegacyMenu()) {
				publicPadMenuLegacyApiRegistered = true
				return true
			}
			if (!publicPadMenuLegacyPluginHooked && window.OC && window.OC.Plugins && typeof window.OC.Plugins.register === 'function') {
				window.OC.Plugins.register('OCA.Files.NewFileMenu', {
					attach(menu) {
						if (!menu || typeof menu.addMenuEntry !== 'function') {
							return
						}
						try {
							menu.addMenuEntry(legacyEntry)
							publicPadMenuLegacyApiRegistered = true
						} catch (error) {
							if (isDuplicateError(error)) {
								publicPadMenuLegacyApiRegistered = true
							}
						}
					},
				})
				publicPadMenuLegacyPluginHooked = true
			}
			return publicPadMenuLegacyApiRegistered
		} catch (error) {
			if (isDuplicateError(error)) {
				publicPadMenuLegacyApiRegistered = true
				return true
			}
			return false
		}
	}

	return () => {
		if (!isFilesAppRoute()) {
			return
		}
		if (publicPadMenuApiRegistered || publicPadMenuLegacyApiRegistered) {
			return
		}
		const token = ++publicPadMenuRegistrationToken
		const attempt = async (step) => {
			if (token !== publicPadMenuRegistrationToken || publicPadMenuApiRegistered || publicPadMenuLegacyApiRegistered) {
				return
			}
			const registered = await registerPublicPadEntryViaNewFileMenuApi()
			if (registered) {
				return
			}
			const legacyRegistered = registerPublicPadEntryViaLegacyPluginApi()
			if (legacyRegistered) {
				return
			}
			if (step < PUBLIC_PAD_MENU_REGISTRATION_MAX_ATTEMPTS) {
				window.setTimeout(() => { void attempt(step + 1) }, PUBLIC_PAD_MENU_REGISTRATION_RETRY_MS)
			}
		}
		void attempt(0)
	}
}
