<?php

declare(strict_types=1);

namespace OCP\Security;

if (!interface_exists(ISecureRandom::class)) {
	interface ISecureRandom {
		public const CHAR_LOWER = 'abcdefghijklmnopqrstuvwxyz';
		public const CHAR_DIGITS = '0123456789';

		public function generate(int $length, string $characters = ''): string;
	}
}
