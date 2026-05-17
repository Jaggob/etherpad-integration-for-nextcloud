<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

class PadPlaceholderResolver {
	private const TOKEN_REGEX = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?::\s*([^|}]+?)\s*)?(?:\|\s*([^}]+?)\s*)?\}\}/i';
	private const DEFAULT_DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private ITimeFactory $timeFactory,
	) {
	}

	public function apply(string $content, ?IUser $user): string {
		return (string)preg_replace_callback(
			self::TOKEN_REGEX,
			fn (array $match): string => $this->resolve($match[1], $match[2] ?? '', $match[3] ?? '', $user, $match[0]),
			$content,
		);
	}

	private function resolve(string $name, string $arg, string $format, ?IUser $user, string $original): string {
		$normalized = strtolower($name);
		[$head, $property] = array_pad(explode('.', $normalized, 2), 2, '');
		switch ($head) {
			case 'date':
				return $this->resolveDate($arg, $format, $original);
			case 'user':
				return $this->resolveUser($property, $user, $original);
			default:
				return $original;
		}
	}

	private function resolveDate(string $arg, string $format, string $original): string {
		$reference = $this->timeFactory->getTime();
		$expression = $arg === '' ? 'today' : $arg;
		$timestamp = strtotime($expression, $reference);
		if ($timestamp === false) {
			return $original;
		}
		$effectiveFormat = $format === '' ? self::DEFAULT_DATE_FORMAT : $format;
		$rendered = @date($effectiveFormat, $timestamp);
		return is_string($rendered) ? $rendered : $original;
	}

	private function resolveUser(string $arg, ?IUser $user, string $original): string {
		if ($user === null) {
			return $original;
		}
		switch (strtolower($arg)) {
			case '':
				return $user->getDisplayName();
			case 'uid':
				return $user->getUID();
			default:
				return $original;
		}
	}
}
