<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Full metadata read for a file, used by the meta-by-id endpoint. The
 * file itself is always resolved (fileId / name / path are populated);
 * the pad-specific fields are only meaningful when `$isPad` is true.
 */
class PadMeta {
	public function __construct(
		public bool $isPad,
		public int $fileId,
		public string $name,
		public string $path,
		public bool $isPadMime = false,
		public string $accessMode = '',
		public bool $isExternal = false,
		public string $padId = '',
		public string $padUrl = '',
		public string $publicOpenUrl = '',
	) {
	}
}
