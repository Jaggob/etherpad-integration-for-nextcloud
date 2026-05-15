<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Lightweight resolve result for the resolve endpoint. The file is not
 * always reachable — the not-found branch can return either fileId or
 * path depending on what the caller passed in, hence both are nullable.
 * Pad-specific fields are only meaningful when `$isPad` is true.
 */
class PadResolution {
	public function __construct(
		public bool $isPad,
		public ?int $fileId = null,
		public ?string $path = null,
		public bool $isPadMime = false,
		public string $accessMode = '',
		public bool $isExternal = false,
		public string $publicOpenUrl = '',
	) {
	}
}
