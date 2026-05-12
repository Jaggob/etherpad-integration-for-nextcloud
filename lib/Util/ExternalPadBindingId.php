<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

final class ExternalPadBindingId {
	public static function build(string $origin, string $remotePadId, int $fileId): string {
		/*
		 * ext. separates external bindings from managed Etherpad IDs. fileId is
		 * part of the hash so the same external pad can be linked by multiple
		 * .pad files without colliding. 40 hex chars keeps the ID compact while
		 * retaining a SHA-1-length identifier space for this namespace.
		 */
		return 'ext.' . substr(hash('sha256', $origin . '|' . $remotePadId . '|' . $fileId), 0, 40);
	}
}
