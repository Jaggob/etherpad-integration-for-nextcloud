/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

const basicAuthHeader = (): string => {
	const auth = Buffer.from(`${E2E.user}:${E2E.appPassword}`).toString('base64')
	return `Basic ${auth}`
}

const davUrl = (relativePath: string): string => {
	const path = relativePath.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	return `${E2E.baseURL}/remote.php/dav/files/${encodeURIComponent(E2E.user)}/${path}`
}

/**
 * Delete a file or folder through WebDAV. Used for teardown so browser
 * specs do not leave pads behind on a shared target instance.
 */
export const deleteViaDav = async (relativePath: string): Promise<void> => {
	const path = relativePath.replace(/^\/+/, '')
	const res = await fetch(davUrl(path), {
		method: 'DELETE',
		headers: { Authorization: basicAuthHeader() },
	})
	if (!res.ok && res.status !== 404) {
		throw new Error(`WebDAV cleanup DELETE ${path} failed with HTTP ${res.status}`)
	}
}
