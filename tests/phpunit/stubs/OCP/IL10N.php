<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IL10N::class)) {
	interface IL10N {
		/** @param array<string,mixed> $parameters */
		public function t(string $text, array $parameters = []): string;
	}
}
