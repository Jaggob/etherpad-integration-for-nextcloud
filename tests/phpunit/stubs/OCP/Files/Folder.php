<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(Folder::class)) {
	interface Folder {
		public function nodeExists(string $path): bool;

		public function get(string $path): mixed;

		public function newFile(string $name): mixed;

		public function isCreatable(): bool;
	}
}
