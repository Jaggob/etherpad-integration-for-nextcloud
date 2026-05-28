/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { E2E } from './env'

const basicAuthHeader = (): string => {
	const auth = Buffer.from(`${E2E.user}:${E2E.appPassword}`).toString('base64')
	return `Basic ${auth}`
}

const parseJsonResponse = async (res: Response): Promise<unknown> => {
	const text = await res.text()
	try {
		return text !== '' ? JSON.parse(text) : null
	} catch (error) {
		throw new Error(`Expected JSON response but got HTTP ${res.status}: ${text.slice(0, 200)}`)
	}
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

export const createPublicReadShare = async (relativePath: string): Promise<{ token: string, url: string }> => {
	const body = new URLSearchParams()
	body.set('path', '/' + relativePath.replace(/^\/+/, ''))
	body.set('shareType', '3')
	body.set('permissions', '1')

	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json`, {
		method: 'POST',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			Accept: 'application/json',
		},
		body,
	})
	const payload = await parseJsonResponse(res) as {
		ocs?: { meta?: { statuscode?: number, message?: string }, data?: { token?: string, url?: string } }
	}
	const statusCode = Number(payload?.ocs?.meta?.statuscode ?? 0)
	if (!res.ok || statusCode < 100 || statusCode >= 300) {
		throw new Error(`OCS share create failed with HTTP ${res.status} / OCS ${statusCode}: ${payload?.ocs?.meta?.message || 'unknown error'}`)
	}
	const token = String(payload?.ocs?.data?.token || '')
	const url = String(payload?.ocs?.data?.url || '')
	if (token === '' || url === '') {
		throw new Error('OCS share create response did not include token and url.')
	}
	return { token, url }
}

export const deletePublicShare = async (token: string): Promise<void> => {
	if (token === '') {
		return
	}
	const lookup = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json`, {
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	const payload = await parseJsonResponse(lookup) as {
		ocs?: { data?: Array<{ id?: string | number, token?: string }> }
	}
	const share = (payload?.ocs?.data || []).find((item) => String(item.token || '') === token)
	if (!share || share.id === undefined || share.id === null) {
		return
	}
	const res = await fetch(`${E2E.baseURL}/ocs/v2.php/apps/files_sharing/api/v1/shares/${encodeURIComponent(String(share.id))}?format=json`, {
		method: 'DELETE',
		headers: {
			Authorization: basicAuthHeader(),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	if (!res.ok && res.status !== 404) {
		throw new Error(`OCS share delete failed with HTTP ${res.status}`)
	}
}
