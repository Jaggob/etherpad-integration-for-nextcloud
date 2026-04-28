<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(ContentSecurityPolicy::class)) {
	class ContentSecurityPolicy {
		/** @var list<string> */
		private array $frameAncestorDomains = [];
		/** @var list<string> */
		private array $frameDomains = [];
		/** @var list<string> */
		private array $childSrcDomains = [];

		public function addAllowedFrameAncestorDomain(string $domain): void {
			$this->frameAncestorDomains[] = $domain;
		}

		public function addAllowedFrameDomain(string $domain): void {
			$this->frameDomains[] = $domain;
		}

		public function addAllowedChildSrcDomain(string $domain): void {
			$this->childSrcDomains[] = $domain;
		}

		/** @return list<string> */
		public function getAllowedFrameAncestorDomains(): array {
			return $this->frameAncestorDomains;
		}

		/** @return list<string> */
		public function getAllowedFrameDomains(): array {
			return $this->frameDomains;
		}

		/** @return list<string> */
		public function getAllowedChildSrcDomains(): array {
			return $this->childSrcDomains;
		}
	}
}
