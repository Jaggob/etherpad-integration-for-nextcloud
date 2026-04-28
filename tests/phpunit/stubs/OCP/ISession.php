<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(ISession::class)) {
	interface ISession {
		public function get(string $key);

		public function set(string $key, $value): void;
	}
}
