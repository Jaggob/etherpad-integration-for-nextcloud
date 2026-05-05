/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { readdir, rm } from 'node:fs/promises'
import { join } from 'node:path'

const jsDir = new URL('../js/', import.meta.url)
const generatedExtensions = ['.mjs', '.mjs.map', '.mjs.license']

const entries = await readdir(jsDir)
await Promise.all(entries
	.filter((entry) => generatedExtensions.some((extension) => entry.endsWith(extension)))
	.map((entry) => rm(join(jsDir.pathname, entry), { force: true })))
