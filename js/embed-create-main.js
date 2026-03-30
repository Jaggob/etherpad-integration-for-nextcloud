/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
(function () {
	const REQUEST_TIMEOUT_MS = 10000

	const root = document.getElementById('etherpad-nextcloud-embed-create')
	if (!(root instanceof HTMLElement)) {
		return
	}

	const parentFolderId = Number(root.getAttribute('data-parent-folder-id') || '')
	const name = String(root.getAttribute('data-name') || '').trim()
	const accessMode = String(root.getAttribute('data-access-mode') || '').trim()
	const createByParentUrl = String(root.getAttribute('data-create-by-parent-url') || '').trim()
	const templateRequestToken = String(root.getAttribute('data-request-token') || '').trim()
	const loadingNode = root.querySelector('[data-epnc-embed-create-loading]')
	const errorNode = root.querySelector('[data-epnc-embed-create-error]')
	const errorMessageNode = root.querySelector('[data-epnc-embed-create-error-message]')

	const ocRequestToken = () => {
		if (templateRequestToken !== '') {
			return templateRequestToken
		}
		return String((window.OC && window.OC.requestToken) || '')
	}

	const showError = (message) => {
		if (loadingNode instanceof HTMLElement) {
			loadingNode.hidden = true
		}
		if (errorMessageNode instanceof HTMLElement) {
			errorMessageNode.textContent = String(message || 'Unknown error.')
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = false
		}
	}

	const fetchJson = async (url, init = {}) => {
		const controller = new AbortController()
		const timeoutId = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
		const headers = Object.assign({ Accept: 'application/json' }, init.headers || {})
		try {
			const response = await fetch(url, Object.assign({
				credentials: 'same-origin',
				headers,
				signal: controller.signal,
			}, init))
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error((data && data.message) || 'Request failed.')
			}
			return data
		} catch (error) {
			if (error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') {
				throw new Error('Request timed out.')
			}
			throw error
		} finally {
			window.clearTimeout(timeoutId)
		}
	}

	const run = async () => {
		if (!Number.isFinite(parentFolderId) || parentFolderId <= 0 || name === '' || createByParentUrl === '') {
			showError('Embed configuration is incomplete.')
			return
		}
		if (ocRequestToken() === '') {
			showError('CSRF request token is missing.')
			return
		}

		const body = new URLSearchParams()
		body.set('parentFolderId', String(parentFolderId))
		body.set('name', name)
		if (accessMode !== '') {
			body.set('accessMode', accessMode)
		}

		try {
			const data = await fetchJson(createByParentUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: ocRequestToken(),
				},
				body: body.toString(),
			})
			if (!data || typeof data.embed_url !== 'string' || data.embed_url.trim() === '') {
				throw new Error('Pad creation API did not return a valid embed URL.')
			}
			window.location.replace(data.embed_url)
		} catch (error) {
			showError(error instanceof Error ? error.message : 'Pad creation failed.')
		}
	}

	void run()
})()
