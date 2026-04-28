<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Lock\LockedException;

class PadFileLockRetryService {
	private const OPEN_LOCK_RETRY_DELAYS_US = [100000, 200000, 400000];
	private const SYNC_LOCK_RETRY_DELAYS_US = [150000, 300000, 600000];

	public function readContentWithOpenLockRetry(File $node): string {
		foreach (self::OPEN_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				return (string)$node->getContent();
			} catch (LockedException) {
				\usleep($delay);
			}
		}

		return (string)$node->getContent();
	}

	public function putContentWithSyncLockRetry(File $node, string $content, int &$lockRetries): void {
		foreach (self::SYNC_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				$node->putContent($content);
				return;
			} catch (LockedException) {
				\usleep($delay);
				$lockRetries++;
			}
		}

		$node->putContent($content);
	}
}
