<?php

declare(strict_types=1);

namespace OCP\Http\Client;

if (!interface_exists(IClientService::class)) {
	interface IClientService {
		public function newClient(): IClient;
	}
}
