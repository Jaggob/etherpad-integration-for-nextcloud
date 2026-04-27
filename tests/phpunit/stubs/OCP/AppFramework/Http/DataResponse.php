<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(DataResponse::class)) {
	class DataResponse {
		/** @param array<string,mixed> $data */
		public function __construct(
			private array $data = [],
			private int $status = 200,
		) {
		}

		/** @var array<string,string> */
		private array $headers = [];

		/** @return array<string,mixed> */
		public function getData(): array {
			return $this->data;
		}

		public function getStatus(): int {
			return $this->status;
		}

		public function addHeader(string $name, string $value): self {
			$this->headers[$name] = $value;
			return $this;
		}

		/** @return array<string,string> */
		public function getHeaders(): array {
			return $this->headers;
		}
	}
}
