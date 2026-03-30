/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
(function () {
	const REQUEST_TIMEOUT_MS = 10000

	const root = document.getElementById('etherpad-nextcloud-embed')
	if (!(root instanceof HTMLElement)) {
		return
	}

	const fileId = Number(root.getAttribute('data-file-id') || '')
	const openByIdUrl = String(root.getAttribute('data-open-by-id-url') || '').trim()
	const initializeByIdUrlTemplate = String(root.getAttribute('data-initialize-by-id-url-template') || '').trim()
	const templateRequestToken = String(root.getAttribute('data-request-token') || '').trim()
	const loadingNode = root.querySelector('[data-epnc-embed-loading]')
	const errorNode = root.querySelector('[data-epnc-embed-error]')
	const errorMessageNode = root.querySelector('[data-epnc-embed-error-message]')
	const iframe = root.querySelector('[data-epnc-embed-iframe]')

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
		if (iframe instanceof HTMLIFrameElement) {
			iframe.hidden = true
			iframe.removeAttribute('src')
		}
		if (errorMessageNode instanceof HTMLElement) {
			errorMessageNode.textContent = String(message || 'Unknown error.')
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = false
		}
	}

	const showIframe = (url) => {
		if (!(iframe instanceof HTMLIFrameElement)) {
			showError('Embed iframe is not available.')
			return
		}
		if (loadingNode instanceof HTMLElement) {
			loadingNode.hidden = true
		}
		if (errorNode instanceof HTMLElement) {
			errorNode.hidden = true
		}
		iframe.src = url
		iframe.hidden = false
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

	const isMissingFrontmatterError = (error) => {
		if (!(error instanceof Error)) {
			return false
		}
		return String(error.message || '').includes('Missing YAML frontmatter')
	}

	const openPad = async () => {
		const body = new URLSearchParams()
		body.set('fileId', String(fileId))
		const data = await fetchJson(openByIdUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
				requesttoken: ocRequestToken(),
			},
			body: body.toString(),
		})
		if (!data || typeof data.url !== 'string' || data.url.trim() === '') {
			throw new Error('Pad open API did not return a valid URL.')
		}
		return data
	}

	const initializePad = async () => {
		const url = initializeByIdUrlTemplate.replace('__FILE_ID__', encodeURIComponent(String(fileId)))
		await fetchJson(url, {
			method: 'POST',
			headers: {
				requesttoken: ocRequestToken(),
			},
		})
	}

	const run = async () => {
		if (!Number.isFinite(fileId) || fileId <= 0 || openByIdUrl === '' || initializeByIdUrlTemplate === '') {
			showError('Embed configuration is incomplete.')
			return
		}
		if (ocRequestToken() === '') {
			showError('CSRF request token is missing.')
			return
		}
		try {
			let data
			try {
				data = await openPad()
			} catch (error) {
				if (!isMissingFrontmatterError(error)) {
					throw error
				}
				await initializePad()
				data = await openPad()
			}
			showIframe(data.url)
		} catch (error) {
			showError(error instanceof Error ? error.message : 'Pad open failed.')
		}
	}

	void run()
})()
