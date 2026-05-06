/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { describe, expect, it } from 'vitest'

import { resolvePublicSharePadPathFromLink } from '../../../src/files/public-share-pad-links.js'

const linkFor = (href) => {
	const link = document.createElement('a')
	link.setAttribute('href', href)
	return link
}

describe('public share pad link resolution', () => {
	it('reads pad paths from public share download links with matching token', () => {
		const link = linkFor('/index.php/s/share-token/download?path=/Folder&files=Pad.pad')

		expect(resolvePublicSharePadPathFromLink(link, 'share-token')).toBe('/Folder/Pad.pad')
	})

	it('ignores public share download links for a different token', () => {
		const link = linkFor('/index.php/s/other-token/download?path=/Folder&files=Pad.pad')

		expect(resolvePublicSharePadPathFromLink(link, 'share-token')).toBeNull()
	})

	it('reads pad paths from DAV hrefs', () => {
		const link = linkFor('/remote.php/dav/files/user/Documents/Pad.pad')

		expect(resolvePublicSharePadPathFromLink(link, 'share-token')).toBe('/Documents/Pad.pad')
	})

	it('ignores non-pad links', () => {
		const link = linkFor('/index.php/s/share-token/download?path=/Folder&files=Notes.txt')

		expect(resolvePublicSharePadPathFromLink(link, 'share-token')).toBeNull()
	})
})
