<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

use OCP\Lock\LockedException;

class PadFileLockRetryExhaustedException extends \RuntimeException {
	public function __construct(
		private int $retryAttempts,
		private LockedException $lockedException,
	) {
		parent::__construct($lockedException->getMessage(), (int)$lockedException->getCode(), $lockedException);
	}

	public function getRetryAttempts(): int {
		return $this->retryAttempts;
	}

	public function getLockedException(): LockedException {
		return $this->lockedException;
	}
}
