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
		// Pin to UTC so resolver output is deterministic across server timezones —
		// otherwise `strtotime`/`date` follow `date_default_timezone_get()` and
		// "today" / "next monday" drift by ±1 day on western-tz hosts.
		$reference = $this->timeFactory->getTime();
		$expression = $arg === '' ? 'today' : $arg;
		try {
			$utc = new \DateTimeZone('UTC');
			$base = (new \DateTimeImmutable('@' . $reference))->setTimezone($utc);
			$resolved = $base->modify($expression);
			if ($resolved === false) {
				return $original;
			}
		} catch (\Throwable) {
			return $original;
		}

		$effectiveFormat = $format === '' ? self::DEFAULT_DATE_FORMAT : $format;
		try {
			return $resolved->format($effectiveFormat);
		} catch (\Throwable) {
			return $original;
		}
	}

	private function resolveUser(string $arg, ?IUser $user, string $original): string {
		if ($user === null) {
			return $original;
		}
		switch (strtolower($arg)) {
			case '':
				return $this->sanitizePathLikeValue($user->getDisplayName());
			case 'uid':
				return $this->sanitizePathLikeValue($user->getUID());
			default:
				return $original;
		}
	}

	/**
	 * Strip characters that could break out of a filename segment when the
	 * resolved value is interpolated into a user-supplied path (e.g. a target
	 * file name in `createFromTemplate`). Display names are free-form and may
	 * contain `/`, `\`, NUL or `..` — none of which belong inside a single
	 * filename segment. We collapse runs of separators to a single space so
	 * the result remains human-readable.
	 */
	private function sanitizePathLikeValue(string $value): string {
		$cleaned = str_replace(["\0", "\r", "\n", "\t"], '', $value);
		$cleaned = preg_replace('#[/\\\\]+#', ' ', $cleaned) ?? $cleaned;
		// Disallow leading dots so a display name "..foo" can't become "../foo"
		// after concatenation with separators.
		$cleaned = preg_replace('/^\.+/', '', $cleaned) ?? $cleaned;
		return trim($cleaned);
	}
}
