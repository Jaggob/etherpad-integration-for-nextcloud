<?php

declare(strict_types=1);

namespace OC\Security\CSRF;

if (!class_exists(CsrfToken::class)) {
	class CsrfToken {
		public function __construct(private string $encryptedValue) {
		}

		public function getEncryptedValue(): string {
			return $this->encryptedValue;
		}
	}
}
