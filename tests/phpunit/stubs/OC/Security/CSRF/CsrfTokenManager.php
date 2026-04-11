<?php

declare(strict_types=1);

namespace OC\Security\CSRF;

if (!class_exists(CsrfTokenManager::class)) {
	class CsrfTokenManager {
		public function __construct(private ?CsrfToken $token = null) {
		}

		public function getToken(): CsrfToken {
			return $this->token ?? new CsrfToken('stub-token');
		}
	}
}
