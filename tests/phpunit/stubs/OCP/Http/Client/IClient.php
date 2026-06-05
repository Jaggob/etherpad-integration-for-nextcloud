<?php

declare(strict_types=1);

namespace OCP\Http\Client;

if (!interface_exists(IClient::class)) {
	interface IClient {
		/** @param array<string,mixed> $options */
		public function request(string $method, string $uri, array $options = []): IResponse;

		public function getResponseFromThrowable(\Throwable $e): IResponse;
	}
}
