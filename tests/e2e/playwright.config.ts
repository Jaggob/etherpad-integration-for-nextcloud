/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { config as loadEnv } from 'dotenv'

const here = dirname(fileURLToPath(import.meta.url))

// Load tests/e2e/.env.e2e if present (gitignored). All real values —
// base URL, user, passwords — live there, never in the repo.
loadEnv({ path: resolve(here, '.env.e2e') })

const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080'

export default defineConfig({
	testDir: resolve(here, 'specs'),
	// One worker by default: tests run against a shared real instance,
	// so serial execution avoids create/cleanup races. Bump locally with
	// --workers if your target can take it.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'never' }]],
	outputDir: resolve(here, '../../test-results'),

	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		// NC's session cookies need a real browser context; storageState is
		// produced by the "setup" project below.
		ignoreHTTPSErrors: false,
	},

	projects: [
		{
			name: 'setup',
			testMatch: /auth\.setup\.ts$/,
		},
		{
			name: 'chromium',
			dependencies: ['setup'],
			use: {
				...devices['Desktop Chrome'],
				storageState: resolve(here, '.auth/state.json'),
			},
		},
	],
})
