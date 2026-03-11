<?php

declare(strict_types=1);

namespace OCP\Files;

if (!interface_exists(IRootFolder::class)) {
	interface IRootFolder {
		public function getUserFolder(string $uid): Folder;

		/** @return array<int,mixed> */
		public function getById(int $id): array;
	}
}
