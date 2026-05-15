<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Result of looking up the original `.pad` file behind a copy. When
 * `$found` is false the other fields are unset on purpose — see the
 * authorization design in `PadMetadataService::findOriginalForCopy`.
 */
class PadOriginalLookup {
	public function __construct(
		public bool $found,
		public ?int $fileId = null,
		public ?string $path = null,
	) {
	}
}
