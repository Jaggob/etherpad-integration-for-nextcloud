<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class SnapshotExtractor {
	public function __construct(
		private PadFileService $padFileService,
		private SnapshotHtmlSanitizer $snapshotHtmlSanitizer,
	) {
	}

	public function extract(string $padFileContent): SnapshotPayload {
		return new SnapshotPayload(
			$this->padFileService->getTextSnapshotForRestore($padFileContent),
			$this->snapshotHtmlSanitizer->sanitize($this->padFileService->getHtmlSnapshotForRestore($padFileContent)),
		);
	}
}
