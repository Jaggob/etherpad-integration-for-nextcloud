<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IGroupManager::class)) {
	interface IGroupManager {
		public function isAdmin(string $uid): bool;
	}
}
