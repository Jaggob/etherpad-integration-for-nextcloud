<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

class UnsupportedTestFaultException extends \InvalidArgumentException {
	/** @param list<string> $supportedFaults */
	public function __construct(
		private array $supportedFaults,
		string $message = 'Unsupported test fault.',
	) {
		parent::__construct($message);
	}

	/** @return list<string> */
	public function getSupportedFaults(): array {
		return $this->supportedFaults;
	}
}
