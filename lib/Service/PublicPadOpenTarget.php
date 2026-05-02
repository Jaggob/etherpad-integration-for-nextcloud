<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class PublicPadOpenTarget {
	public function __construct(
		public string $url,
		public string $originalPadUrl,
		public string $cookieHeader,
		public bool $isReadOnlySnapshot,
		public string $snapshotText,
		public string $snapshotHtml,
	) {
	}
}
