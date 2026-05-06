/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { ignoreExpectedNavigationResult } from '../../../src/lib/nextcloud-runtime.js'

describe('Nextcloud runtime helpers', () => {
	it('accepts promise-like navigation results without a catch method', () => {
		const thenable = {
			then(resolve) {
				resolve()
			},
		}

		expect(() => ignoreExpectedNavigationResult(thenable)).not.toThrow()
	})
})
