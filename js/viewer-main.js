/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
(function () {
	const APP_ID = 'etherpad_nextcloud'
	const MIME = 'application/x-etherpad-nextcloud'
	const HANDLER_ID = 'etherpad_nextcloud'
	let attempts = 0
	const ocGenerateUrl = (path) => {
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl(path)
		}
		return '/index.php' + path
	}
	const ocRequestToken = () => String((window.OC && window.OC.requestToken) || '')

	const parsePublicShareTokenFromLocation = () => {
		const match = (window.location.pathname || '').match(/(?:\/index\.php)?\/s\/([^/]+)(?:\/.*)?$/)
		if (!match) {
			return null
		}
		return match[1] || null
	}

	const component = {
		name: 'EtherpadNextcloudViewer',
		props: {
			filename: { type: String, required: false, default: '' },
			basename: { type: String, required: false, default: '' },
			source: { type: String, required: false, default: '' },
			fileid: { type: [String, Number], required: false, default: null },
			fileId: { type: [String, Number], required: false, default: null },
			fileInfo: { type: Object, required: false, default: null },
		},
		data() {
			return {
				iframeSrc: '',
				isLoading: true,
				loadError: '',
				resolveGeneration: 0,
				syncUrl: '',
				syncIntervalMs: 120000,
				syncInFlight: false,
				syncTimerId: null,
				visibilityHandler: null,
				pageHideHandler: null,
			}
		},
		computed: {
			sourcePath() {
				const value = typeof this.source === 'string' ? this.source.trim() : ''
				if (!value) return ''
				try {
					const url = new URL(value, window.location.origin)
					const path = decodeURIComponent(url.pathname || '')
					const markers = ['/remote.php/dav/files/', '/public.php/dav/files/']
					const marker = markers.find((candidate) => path.includes(candidate))
					if (!marker) return ''
					const idx = path.indexOf(marker)
					const rest = path.substring(idx + marker.length)
					const slash = rest.indexOf('/')
					if (slash === -1) return ''
					return '/' + rest.substring(slash + 1)
				} catch {
					return ''
				}
			},
			filePath() {
				const normalizeName = (value) => String(value || '').trim().replace(/\s+\.pad$/i, '.pad')
				const normalizeDir = (value) => {
					const dir = String(value || '').trim()
					if (!dir || dir === '/') return '/'
					return dir.startsWith('/') ? dir : ('/' + dir)
				}
				const joinPath = (dir, name) => {
					if (!name) return ''
					if (name.startsWith('/')) return name
					const normalizedDir = normalizeDir(dir)
					return normalizedDir === '/' ? '/' + name : normalizedDir + '/' + name
				}
				const isPadPath = (value) => typeof value === 'string' && value.toLowerCase().endsWith('.pad')
				if (isPadPath(this.sourcePath)) return this.sourcePath

				const info = this.fileInfo && typeof this.fileInfo === 'object' ? this.fileInfo : null
				const infoPath = info && typeof info.path === 'string' ? normalizeName(info.path) : ''
				if (isPadPath(infoPath)) return infoPath.startsWith('/') ? infoPath : ('/' + infoPath)

				const baseName = normalizeName(this.filename || this.basename || (info && (info.name || info.basename)) || '')
				if (!baseName) return ''

				const infoDir = info && typeof info.dirname === 'string' ? info.dirname : ''
				if (infoDir) {
					const combined = joinPath(infoDir, baseName)
					if (isPadPath(combined)) return combined
				}

				const params = new URLSearchParams(window.location.search || '')
				const urlDir = params.get('dir') || '/'
				const fromDir = joinPath(urlDir, baseName)
				if (isPadPath(fromDir)) return fromDir
				return '/' + baseName
			},
			resolvedFileId() {
				const candidates = [this.fileid, this.fileId, this.fileInfo && (this.fileInfo.fileid || this.fileInfo.fileId || this.fileInfo.id)]
				for (const candidate of candidates) {
					const numeric = Number(candidate)
					if (Number.isFinite(numeric) && numeric > 0) return numeric
				}
				const params = new URLSearchParams(window.location.search || '')
				if (params.get('openfile') !== 'true') return null
				const match = (window.location.pathname || '').match(/\/apps\/files\/files\/(\d+)\/?$/)
				if (!match) return null
				const fallbackId = Number(match[1])
				return Number.isFinite(fallbackId) && fallbackId > 0 ? fallbackId : null
			},
		},
		watch: {
			filePath: { immediate: true, handler() { void this.resolveOpenUrl() } },
			resolvedFileId() { void this.resolveOpenUrl() },
		},
		methods: {
			async fetchOpenPayload(url, init = {}) {
				const headers = Object.assign({ Accept: 'application/json' }, init.headers || {})
				const response = await fetch(url, Object.assign({
					method: 'GET',
					credentials: 'same-origin',
					headers,
				}, init))
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error((data && data.message) || 'Pad open failed.')
				}
				if (!data || typeof data.url !== 'string' || data.url.trim() === '') {
					throw new Error('Pad open API did not return a valid URL.')
				}
				return data
			},
			isMissingFrontmatterError(error) {
				if (!(error instanceof Error)) return false
				return String(error.message || '').includes('Missing YAML frontmatter')
			},
			async initializeMissingFrontmatter() {
				const headers = {
					Accept: 'application/json',
					requesttoken: ocRequestToken(),
				}

				if (this.resolvedFileId !== null) {
					const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize-by-id/' + encodeURIComponent(String(this.resolvedFileId)))
					const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers })
					const data = await response.json().catch(() => ({}))
					if (!response.ok) {
						throw new Error((data && data.message) || 'Pad initialization failed.')
					}
					return data
				}

				if (!this.filePath) {
					throw new Error('Pad initialization failed: missing file path.')
				}

				const body = new URLSearchParams()
				body.set('file', this.filePath)
				const url = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/initialize')
				const response = await fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: Object.assign({}, headers, {
						'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					}),
					body: body.toString(),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error((data && data.message) || 'Pad initialization failed.')
				}
				return data
			},
			markLoaded() {
				this.$emit('update:loaded', true)
			},
			stopSyncLoop() {
				if (this.syncTimerId !== null) {
					window.clearInterval(this.syncTimerId)
					this.syncTimerId = null
				}
			},
			startSyncLoop() {
				if (!this.syncUrl || this.syncTimerId !== null) {
					return
				}
				this.syncTimerId = window.setInterval(() => {
					if (document.visibilityState === 'visible') {
						void this.runSync(false, false)
					}
				}, this.syncIntervalMs)
			},
			async runSync(force, keepalive) {
				if (!this.syncUrl) return
				if (this.syncInFlight && !force) return
				this.syncInFlight = true
				try {
					const url = force ? (this.syncUrl + (this.syncUrl.includes('?') ? '&' : '?') + 'force=1') : this.syncUrl
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
					this.syncInFlight = false
				}
			},
			installSyncLifecycleHandlers() {
				if (this.visibilityHandler || this.pageHideHandler) {
					return
				}
				this.visibilityHandler = () => {
					if (document.visibilityState === 'hidden') {
						void this.runSync(true, true)
						this.stopSyncLoop()
						return
					}
					this.startSyncLoop()
				}
				this.pageHideHandler = () => {
					void this.runSync(true, true)
					this.stopSyncLoop()
				}
				document.addEventListener('visibilitychange', this.visibilityHandler)
				window.addEventListener('pagehide', this.pageHideHandler)
			},
			removeSyncLifecycleHandlers() {
				if (this.visibilityHandler) {
					document.removeEventListener('visibilitychange', this.visibilityHandler)
					this.visibilityHandler = null
				}
				if (this.pageHideHandler) {
					window.removeEventListener('pagehide', this.pageHideHandler)
					this.pageHideHandler = null
				}
			},
			async resolveOpenUrl() {
				const generation = ++this.resolveGeneration
				const isCurrent = () => generation === this.resolveGeneration

				this.isLoading = true
				this.loadError = ''
				this.iframeSrc = ''
				this.syncUrl = ''
				this.syncInFlight = false
				this.stopSyncLoop()

				if (!this.filePath) {
					if (!isCurrent()) return
					this.loadError = 'No .pad file selected.'
					this.isLoading = false
					return
				}

				const publicToken = parsePublicShareTokenFromLocation()
				const byPathUrl = ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open')
				const byPublicUrl = (() => {
					if (!publicToken) return ''
					const url = new URL(ocGenerateUrl('/apps/' + APP_ID + '/api/v1/public/open/' + encodeURIComponent(publicToken)), window.location.origin)
					url.searchParams.set('file', this.filePath)
					return url.toString()
				})()
				const byIdUrl = this.resolvedFileId !== null
					? ocGenerateUrl('/apps/' + APP_ID + '/api/v1/pads/open-by-id')
					: ''
				const byPathBody = new URLSearchParams()
				byPathBody.set('file', this.filePath)
				const byIdBody = new URLSearchParams()
				if (this.resolvedFileId !== null) {
					byIdBody.set('fileId', String(this.resolvedFileId))
				}
				const openPostHeaders = {
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					requesttoken: ocRequestToken(),
				}

				try {
					const fetchOpenData = async () => {
							let data = null
							if (byPublicUrl) {
								data = await this.fetchOpenPayload(byPublicUrl)
							} else if (byIdUrl) {
								try {
									data = await this.fetchOpenPayload(byIdUrl, {
										method: 'POST',
										headers: openPostHeaders,
										body: byIdBody.toString(),
									})
								} catch {
									// Fallback for moved/renamed files where stale fileId can fail.
								}
							}
							if (!data) {
								data = await this.fetchOpenPayload(byPathUrl, {
									method: 'POST',
									headers: openPostHeaders,
									body: byPathBody.toString(),
								})
							}
							return data
						}

					let data
					try {
						data = await fetchOpenData()
						if (!isCurrent()) return
					} catch (error) {
						if (!this.isMissingFrontmatterError(error)) {
							throw error
						}
						await this.initializeMissingFrontmatter()
						if (!isCurrent()) return
						data = await fetchOpenData()
						if (!isCurrent()) return
					}

					this.iframeSrc = data.url
					this.syncUrl = (data && typeof data.sync_url === 'string') ? data.sync_url : ''

					const intervalSeconds = Number(data && data.sync_interval_seconds)
					this.syncIntervalMs = (Number.isFinite(intervalSeconds) && intervalSeconds > 0)
						? Math.max(5000, Math.min(3600000, intervalSeconds * 1000))
						: 120000

					this.installSyncLifecycleHandlers()
					if (this.syncUrl) {
						this.startSyncLoop()
					}
					this.markLoaded()
				} catch (error) {
					if (!isCurrent()) return
					this.loadError = error instanceof Error ? error.message : 'Could not load pad.'
					this.markLoaded()
				} finally {
					if (!isCurrent()) return
					this.isLoading = false
				}
			},
		},
		beforeDestroy() {
			this.resolveGeneration += 1
			void this.runSync(true, true)
			this.stopSyncLoop()
			this.removeSyncLifecycleHandlers()
		},
		beforeUnmount() {
			this.resolveGeneration += 1
			void this.runSync(true, true)
			this.stopSyncLoop()
			this.removeSyncLifecycleHandlers()
		},
		render(createElement) {
			if (this.loadError) {
				return createElement('div', { class: 'epnc-native-status epnc-native-status--error' }, [
					createElement('div', { class: 'epnc-native-error-card' }, [
						createElement('div', { class: 'epnc-native-error-title' }, 'Unable to open pad'),
						createElement('div', { class: 'epnc-native-error-message' }, this.loadError),
					]),
				])
			}
			if (this.isLoading || !this.iframeSrc) {
				return createElement('div', { class: 'epnc-native-status' }, 'Loading pad...')
			}

			return createElement('div', { class: 'epnc-native-shell' }, [
				createElement('iframe', {
					attrs: { src: this.iframeSrc },
					on: { load: () => this.markLoaded(), error: () => this.markLoaded() },
					class: 'epnc-native-iframe',
				}),
			])
		},
	}

	const tryRegister = () => {
		attempts += 1
		if (!(window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.registerHandler === 'function')) {
			if (attempts < 20) window.setTimeout(tryRegister, 500)
			return
		}
		if (Array.isArray(window.OCA.Viewer.availableHandlers)
			&& window.OCA.Viewer.availableHandlers.some((handler) => handler && handler.id === HANDLER_ID)) {
			return
		}
		window.OCA.Viewer.registerHandler({ id: HANDLER_ID, mimes: [MIME], component })
	}

	tryRegister()
})()
