<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IConfig::class)) {
	interface IConfig {
		public function getAppValue(string $appName, string $key, string $default = ''): string;

		public function getSystemValueBool(string $key, bool $default): bool;

		public function setAppValue(string $appName, string $key, string $value): void;
	}
}
