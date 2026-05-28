/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Reads a required environment variable or throws a clear error telling
 * the maintainer to fill in tests/e2e/.env.e2e. Keeps credentials out of
 * the repo — see .env.e2e.example for the expected keys.
 */
export const requireEnv = (name: string): string => {
	const value = process.env[name]
	if (!value || value.trim() === '') {
		throw new Error(
			`Missing env var ${name}. Copy tests/e2e/.env.e2e.example to `
			+ `tests/e2e/.env.e2e and fill in your test instance's values.`,
		)
	}
	return value.trim()
}

export const E2E = {
	get baseURL(): string {
		return requireEnv('E2E_BASE_URL').replace(/\/+$/, '')
	},
	get user(): string {
		return requireEnv('E2E_USER')
	},
	get password(): string {
		return requireEnv('E2E_PASS')
	},
	/**
	 * App password used for non-browser WebDAV setup/teardown (mirrors the
	 * NC_APP_PASSWORD pattern in tests/integration/*.sh). Optional: only the
	 * specs that clean up via WebDAV need it.
	 */
	get appPassword(): string | null {
		const value = process.env.E2E_APP_PASSWORD
		return value && value.trim() !== '' ? value.trim() : null
	},
}
