<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(ContentSecurityPolicy::class)) {
	class ContentSecurityPolicy {
		/** @var list<string> */
		private array $frameAncestorDomains = [];

		public function addAllowedFrameAncestorDomain(string $domain): void {
			$this->frameAncestorDomains[] = $domain;
		}

		/** @return list<string> */
		public function getAllowedFrameAncestorDomains(): array {
			return $this->frameAncestorDomains;
		}
	}
}
