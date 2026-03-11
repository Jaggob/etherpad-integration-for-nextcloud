<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IUser::class)) {
	interface IUser {
		public function getUID(): string;

		public function getDisplayName(): string;
	}
}
