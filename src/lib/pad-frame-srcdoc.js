/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

const escapeAttribute = (value) => String(value || '')
	.replace(/&/g, '&amp;')
	.replace(/"/g, '&quot;')
	.replace(/</g, '&lt;')
	.replace(/>/g, '&gt;')

export const buildPadFrameSrcdoc = (url) => '<!doctype html><html><head><meta charset="utf-8">'
	+ '<style>html,body,iframe{width:100%;height:100%;margin:0;border:0;overflow:hidden}iframe{display:block}</style>'
	+ '</head><body><iframe src="' + escapeAttribute(url) + '" title="Etherpad"></iframe></body></html>'
