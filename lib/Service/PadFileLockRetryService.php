<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCP\Files\File;
use OCP\Lock\LockedException;

/**
 * Retry wrapper for transient Nextcloud file locks. Open and sync paths can
 * hit concurrent locks; instead of failing immediately, callers retry with a
 * short empirical backoff.
 */
class PadFileLockRetryService {
	/*
	 * Exponential backoff in microseconds. Sync waits slightly longer because
	 * putContent usually holds the file lock longer than getContent.
	 */
	private const OPEN_LOCK_RETRY_DELAYS_US = [100000, 200000, 400000];
	private const SYNC_LOCK_RETRY_DELAYS_US = [150000, 300000, 600000];

	/** @var callable(int): void */
	private $sleeper;

	public function __construct(?callable $sleeper = null) {
		$this->sleeper = $sleeper ?? static function (int $delay): void {
			\usleep($delay);
		};
	}

	public function readContentWithOpenLockRetry(File $node): string {
		foreach (self::OPEN_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				return (string)$node->getContent();
			} catch (LockedException) {
				$this->sleep($delay);
			}
		}

		return (string)$node->getContent();
	}

	public function putContentWithSyncLockRetry(File $node, string $content): int {
		$lockRetries = 0;
		foreach (self::SYNC_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				$node->putContent($content);
				return $lockRetries;
			} catch (LockedException) {
				$this->sleep($delay);
				$lockRetries++;
			}
		}

		try {
			$node->putContent($content);
			return $lockRetries;
		} catch (LockedException $e) {
			throw new PadFileLockRetryExhaustedException($lockRetries, $e);
		}
	}

	private function sleep(int $delay): void {
		($this->sleeper)($delay);
	}
}
