/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from '../lib/constants.js'
import { ocImagePath } from '../lib/oc-compat.js'

const suggestFileNameFromPadUrl = (padUrl) => {
	try {
		const url = new URL(padUrl, window.location.origin)
		const decoded = decodeURIComponent(url.pathname || '')
		const match = decoded.match(/\/p\/([^/]+)$/)
		if (match && match[1]) {
			const safe = match[1].replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
			if (safe !== '') {
				return safe + '.pad'
			}
		}
	} catch (e) {
		// fallback below
	}
	return 'Imported pad.pad'
}

const createModalScaffold = (titleText) => {
	const overlay = document.createElement('div')
	overlay.style.position = 'fixed'
	overlay.style.inset = '0'
	overlay.style.background = 'rgba(0, 0, 0, 0.45)'
	overlay.style.display = 'flex'
	overlay.style.alignItems = 'center'
	overlay.style.justifyContent = 'center'
	overlay.style.zIndex = '20000'

	const dialog = document.createElement('div')
	dialog.style.position = 'relative'
	dialog.style.background = 'var(--color-main-background, #fff)'
	dialog.style.borderRadius = '10px'
	dialog.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.25)'
	dialog.style.padding = '18px'
	dialog.style.width = 'min(460px, calc(100vw - 24px))'

	const closeButton = document.createElement('button')
	closeButton.type = 'button'
	closeButton.setAttribute('aria-label', t(APP_ID, 'Close'))
	closeButton.textContent = '×'
	closeButton.style.position = 'absolute'
	closeButton.style.top = '8px'
	closeButton.style.right = '10px'
	closeButton.style.border = 'none'
	closeButton.style.background = 'transparent'
	closeButton.style.fontSize = '22px'
	closeButton.style.cursor = 'pointer'
	closeButton.style.lineHeight = '1'

	const title = document.createElement('h3')
	title.textContent = titleText
	title.style.margin = '0 26px 10px 0'
	title.style.fontSize = '18px'

	dialog.appendChild(closeButton)
	dialog.appendChild(title)
	overlay.appendChild(dialog)
	document.body.appendChild(overlay)

	return { overlay, dialog, closeButton }
}

export const openInternalPublicPadDialog = () => new Promise((resolve) => {
	const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'Internal public pad'))

	const nameLabel = document.createElement('label')
	nameLabel.textContent = t(APP_ID, 'File name')
	nameLabel.style.display = 'block'
	nameLabel.style.marginBottom = '6px'

	const nameInput = document.createElement('input')
	nameInput.type = 'text'
	nameInput.value = t(APP_ID, 'Public pad') + '.pad'
	nameInput.style.width = '100%'
	nameInput.style.boxSizing = 'border-box'
	nameInput.style.marginBottom = '12px'

	const error = document.createElement('p')
	error.style.color = 'var(--color-error, #c62828)'
	error.style.margin = '0 0 12px 0'
	error.style.minHeight = '20px'

	const createButton = document.createElement('button')
	createButton.type = 'button'
	createButton.className = 'primary'
	createButton.textContent = t(APP_ID, 'Create')

	const close = (result) => {
		overlay.remove()
		resolve(result)
	}
	closeButton.addEventListener('click', () => close(null))
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			close(null)
		}
	})
	nameInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			createButton.click()
		}
	})
	createButton.addEventListener('click', () => {
		const name = nameInput.value.trim()
		if (name === '') {
			error.textContent = t(APP_ID, 'File name is required.')
			return
		}
		close(name)
	})

	dialog.appendChild(nameLabel)
	dialog.appendChild(nameInput)
	dialog.appendChild(error)
	dialog.appendChild(createButton)
	nameInput.focus()
	nameInput.select()
})

export const openExternalPublicPadDialog = () => new Promise((resolve) => {
	const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'External public pad (URL)'))

	const urlLabel = document.createElement('label')
	urlLabel.textContent = t(APP_ID, 'External pad URL')
	urlLabel.style.display = 'block'
	urlLabel.style.marginBottom = '6px'

	const urlInput = document.createElement('input')
	urlInput.type = 'url'
	urlInput.value = 'https://'
	urlInput.placeholder = 'https://'
	urlInput.style.width = '100%'
	urlInput.style.boxSizing = 'border-box'
	urlInput.style.marginBottom = '12px'

	const nameLabel = document.createElement('label')
	nameLabel.textContent = t(APP_ID, 'File name')
	nameLabel.style.display = 'block'
	nameLabel.style.marginBottom = '6px'

	const nameInput = document.createElement('input')
	nameInput.type = 'text'
	nameInput.value = 'Imported pad.pad'
	nameInput.style.width = '100%'
	nameInput.style.boxSizing = 'border-box'
	nameInput.style.marginBottom = '12px'

	const error = document.createElement('p')
	error.style.color = 'var(--color-error, #c62828)'
	error.style.margin = '0 0 12px 0'
	error.style.minHeight = '20px'

	const createButton = document.createElement('button')
	createButton.type = 'button'
	createButton.className = 'primary'
	createButton.textContent = t(APP_ID, 'Create')

	const close = (result) => {
		overlay.remove()
		resolve(result)
	}
	closeButton.addEventListener('click', () => close(null))
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			close(null)
		}
	})
	urlInput.addEventListener('blur', () => {
		const candidate = urlInput.value.trim()
		if (candidate.startsWith('http')) {
			nameInput.value = suggestFileNameFromPadUrl(candidate)
		}
	})
	const submit = () => {
		const padUrl = urlInput.value.trim()
		const name = nameInput.value.trim()
		if (padUrl === '') {
			error.textContent = t(APP_ID, 'External pad URL is required.')
			return
		}
		if (name === '') {
			error.textContent = t(APP_ID, 'File name is required.')
			return
		}
		close({ padUrl, name })
	}
	urlInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			submit()
		}
	})
	nameInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			submit()
		}
	})
	createButton.addEventListener('click', submit)

	dialog.appendChild(urlLabel)
	dialog.appendChild(urlInput)
	dialog.appendChild(nameLabel)
	dialog.appendChild(nameInput)
	dialog.appendChild(error)
	dialog.appendChild(createButton)
	urlInput.focus()
	urlInput.select()
})

export const openPublicPadModeDialog = () => new Promise((resolve) => {
	const overlay = document.createElement('div')
	overlay.style.position = 'fixed'
	overlay.style.inset = '0'
	overlay.style.background = 'rgba(0, 0, 0, 0.45)'
	overlay.style.display = 'flex'
	overlay.style.alignItems = 'center'
	overlay.style.justifyContent = 'center'
	overlay.style.zIndex = '20000'

	const dialog = document.createElement('div')
	dialog.style.position = 'relative'
	dialog.style.background = 'var(--color-main-background, #fff)'
	dialog.style.borderRadius = '10px'
	dialog.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.25)'
	dialog.style.padding = '18px'
	dialog.style.width = 'min(420px, calc(100vw - 24px))'

	const title = document.createElement('h3')
	title.textContent = t(APP_ID, 'Create public pad')
	title.style.margin = '0 26px 10px 0'
	title.style.fontSize = '18px'

	const closeButton = document.createElement('button')
	closeButton.type = 'button'
	closeButton.setAttribute('aria-label', t(APP_ID, 'Close'))
	closeButton.textContent = '×'
	closeButton.style.position = 'absolute'
	closeButton.style.top = '8px'
	closeButton.style.right = '10px'
	closeButton.style.border = 'none'
	closeButton.style.background = 'transparent'
	closeButton.style.fontSize = '22px'
	closeButton.style.cursor = 'pointer'
	closeButton.style.lineHeight = '1'

	const hint = document.createElement('p')
	hint.textContent = t(APP_ID, 'Choose how you want to create the public pad.')
	hint.style.margin = '0 0 14px 0'
	hint.style.opacity = '0.8'

	const actions = document.createElement('div')
	actions.style.display = 'grid'
	actions.style.gap = '8px'

	const internalBtn = document.createElement('button')
	internalBtn.type = 'button'
	internalBtn.className = 'primary'
	internalBtn.textContent = t(APP_ID, 'Internal public pad')

	const externalBtn = document.createElement('button')
	externalBtn.type = 'button'
	externalBtn.textContent = t(APP_ID, 'External public pad (URL)')
	const externalBtnIcon = ocImagePath(APP_ID, 'etherpad-icon-color')
	if (externalBtnIcon !== '') {
		externalBtn.style.backgroundImage = `url(${externalBtnIcon})`
	}
	externalBtn.style.backgroundRepeat = 'no-repeat'
	externalBtn.style.backgroundPosition = '12px center'
	externalBtn.style.backgroundSize = '16px 16px'
	externalBtn.style.paddingLeft = '34px'

	const close = (result) => {
		overlay.remove()
		resolve(result)
	}

	closeButton.addEventListener('click', () => close(null))
	internalBtn.addEventListener('click', () => close('internal'))
	externalBtn.addEventListener('click', () => close('external'))
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			close(null)
		}
	})

	actions.appendChild(internalBtn)
	actions.appendChild(externalBtn)
	dialog.appendChild(closeButton)
	dialog.appendChild(title)
	dialog.appendChild(hint)
	dialog.appendChild(actions)
	overlay.appendChild(dialog)
	document.body.appendChild(overlay)
})
