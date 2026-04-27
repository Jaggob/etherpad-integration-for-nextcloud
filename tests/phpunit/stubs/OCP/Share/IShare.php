<?php

declare(strict_types=1);

namespace OCP\Share;

if (!interface_exists(IShare::class)) {
	interface IShare {
		public function getNode();

		public function getPermissions(): int;

		public function getPassword(): ?string;
	}
}
