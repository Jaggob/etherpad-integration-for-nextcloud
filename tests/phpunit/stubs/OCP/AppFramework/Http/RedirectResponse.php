<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(RedirectResponse::class)) {
	class RedirectResponse {
		public function __construct(private string $redirectUrl) {
		}

		public function getRedirectURL(): string {
			return $this->redirectUrl;
		}
	}
}
