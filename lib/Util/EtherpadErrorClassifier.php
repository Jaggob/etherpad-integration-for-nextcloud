<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

final class EtherpadErrorClassifier {
	public static function isPadAlreadyDeleted(\Throwable $error): bool {
		$current = $error;
		while ($current !== null) {
			$message = strtolower(trim($current->getMessage()));
			if (
				$message !== '' && (
					str_contains($message, 'padid does not exist')
					|| str_contains($message, 'pad does not exist')
					|| str_contains($message, 'unknown pad')
				)
			) {
				return true;
			}
			$current = $current->getPrevious();
		}

		return false;
	}
}
