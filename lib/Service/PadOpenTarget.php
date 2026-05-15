<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Outcome of an authenticated `.pad` open. Kept separate from
 * `PublicPadOpenTarget`: the internal flow always carries the bound
 * `pad_id` / `access_mode` and the file's userspace path, while the
 * public flow can degrade to a read-only snapshot payload that has
 * none of those fields.
 */
class PadOpenTarget {
	public function __construct(
		public string $file,
		public int $fileId,
		public string $padId,
		public string $accessMode,
		public string $padUrl,
		public bool $isExternal,
		public string $originalPadUrl,
		public string $snapshotText,
		public string $snapshotHtml,
		public string $url,
		public string $cookieHeader,
	) {
	}
}
