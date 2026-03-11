<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(File::class)) {
	interface File {
		public function getId(): int;

		public function getName(): string;

		public function getPath(): string;

		public function getMimeType(): string;

		public function getContent();

		public function putContent($data): void;
	}
}
