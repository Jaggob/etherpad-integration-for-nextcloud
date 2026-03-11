<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

class AdminValidationException extends \InvalidArgumentException {
	public function __construct(
		private string $field,
		string $message,
	) {
		parent::__construct($message);
	}

	public function getField(): string {
		return $this->field;
	}
}
