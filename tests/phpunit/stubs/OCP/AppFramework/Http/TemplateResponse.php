<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http;

if (!class_exists(TemplateResponse::class)) {
	class TemplateResponse {
		/** @var array<string,mixed> */
		private array $params;
		private ?ContentSecurityPolicy $contentSecurityPolicy = null;

		/** @param array<string,mixed> $params */
		public function __construct(
			private string $appName,
			private string $templateName,
			array $params = [],
			private string $renderAs = 'blank',
		) {
			$this->params = $params;
		}

		/** @return array<string,mixed> */
		public function getParams(): array {
			return $this->params;
		}

		public function getTemplateName(): string {
			return $this->templateName;
		}

		public function getRenderAs(): string {
			return $this->renderAs;
		}

		public function setContentSecurityPolicy(ContentSecurityPolicy $contentSecurityPolicy): void {
			$this->contentSecurityPolicy = $contentSecurityPolicy;
		}

		public function getContentSecurityPolicy(): ?ContentSecurityPolicy {
			return $this->contentSecurityPolicy;
		}
	}
}
