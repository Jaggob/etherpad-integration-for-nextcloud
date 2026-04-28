<?php

declare(strict_types=1);

namespace OCP\Share;

if (!interface_exists(IManager::class)) {
	interface IManager {
		public function getShareByToken(string $token);
	}
}
