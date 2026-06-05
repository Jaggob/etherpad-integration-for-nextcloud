<?php

declare(strict_types=1);

namespace OCP\Http\Client;

if (!interface_exists(IResponse::class)) {
	interface IResponse {
		/** @return string|resource */
		public function getBody();

		public function getStatusCode(): int;

		public function getHeader(string $key): string;

		/** @return array<string,list<string>> */
		public function getHeaders(): array;
	}
}
