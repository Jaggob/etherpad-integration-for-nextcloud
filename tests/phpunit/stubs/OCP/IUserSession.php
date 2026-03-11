<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IUserSession::class)) {
	interface IUserSession {
		public function getUser(): ?IUser;
	}
}
