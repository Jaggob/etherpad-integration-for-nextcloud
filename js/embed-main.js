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
	const trustedOrigins = String(root.getAttribute('data-trusted-origins') || '')
		.split(/\s+/)
		.map((value) => value.trim())
		.filter(Boolean)
	const loadingNode = root.querySelector('[data-epnc-embed-loading]')
	const errorNode = root.querySelector('[data-epnc-embed-error]')
	const errorMessageNode = root.querySelector('[data-epnc-embed-error-message]')
	const iframe = root.querySelector('[data-epnc-embed-iframe]')
	let syncUrl = ''
	let syncIntervalMs = 120000
	let syncInFlight = false
	let syncTimerId = null
	let visibilityHandler = null
	let pageHideHandler = null
	let messageHandler = null

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

	const stopSyncLoop = () => {
		if (syncTimerId !== null) {
			window.clearInterval(syncTimerId)
			syncTimerId = null
		}
	}

	const runSync = async (force, keepalive) => {
		if (!syncUrl) return
		if (syncInFlight && !force) return
		syncInFlight = true
		try {
			const url = force ? (syncUrl + (syncUrl.includes('?') ? '&' : '?') + 'force=1') : syncUrl
			await fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					requesttoken: ocRequestToken(),
				},
				keepalive: Boolean(keepalive),
			})
		} finally {
			syncInFlight = false
		}
	}

	const startSyncLoop = () => {
		if (!syncUrl || syncTimerId !== null) {
			return
		}
		syncTimerId = window.setInterval(() => {
			if (document.visibilityState === 'visible') {
				void runSync(false, false)
			}
		}, syncIntervalMs)
	}

	const installSyncLifecycleHandlers = () => {
		if (visibilityHandler || pageHideHandler) {
			return
		}
		visibilityHandler = () => {
			if (document.visibilityState === 'hidden') {
				void runSync(true, true)
				stopSyncLoop()
				return
			}
			startSyncLoop()
		}
		pageHideHandler = () => {
			void runSync(true, true)
			stopSyncLoop()
		}
		document.addEventListener('visibilitychange', visibilityHandler)
		window.addEventListener('pagehide', pageHideHandler)
	}

	const isAllowedMessageOrigin = (origin) => {
		if (!origin || origin === 'null') {
			return false
		}
		if (origin === window.location.origin) {
			return true
		}
		return trustedOrigins.includes(origin)
	}

	const installHostMessageHandler = () => {
		if (messageHandler) {
			return
		}
		messageHandler = (event) => {
			if (!isAllowedMessageOrigin(String(event.origin || ''))) {
				return
			}
			const payload = event.data
			const type = typeof payload === 'string'
				? payload
				: (payload && typeof payload === 'object' && typeof payload.type === 'string' ? payload.type : '')
			if (!type) {
				return
			}
			if (type === 'epnc:host-visible') {
				startSyncLoop()
				return
			}
			if (type === 'epnc:host-hidden' || type === 'epnc:host-before-close' || type === 'epnc:host-sync-now') {
				void runSync(true, type !== 'epnc:host-sync-now')
				if (type !== 'epnc:host-sync-now') {
					stopSyncLoop()
				}
			}
		}
		window.addEventListener('message', messageHandler)
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
			syncUrl = typeof data.sync_url === 'string' ? data.sync_url.trim() : ''
			const intervalSeconds = Number(data.sync_interval_seconds)
			syncIntervalMs = Number.isFinite(intervalSeconds) && intervalSeconds > 0 ? intervalSeconds * 1000 : 120000
			syncInFlight = false
			installSyncLifecycleHandlers()
			installHostMessageHandler()
			startSyncLoop()
			showIframe(data.url)
		} catch (error) {
			showError(error instanceof Error ? error.message : 'Pad open failed.')
		}
	}

	void run()
})()
