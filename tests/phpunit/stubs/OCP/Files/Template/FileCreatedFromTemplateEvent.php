<?php

declare(strict_types=1);

namespace OCP\Files\Template;

if (!class_exists(FileCreatedFromTemplateEvent::class)) {
	class FileCreatedFromTemplateEvent extends \OCP\EventDispatcher\Event {
		/**
		 * @param array<string,mixed> $templateFields
		 */
		public function __construct(
			private ?\OCP\Files\File $template,
			private \OCP\Files\File $target,
			private array $templateFields = [],
		) {
		}

		public function getTemplate(): ?\OCP\Files\File {
			return $this->template;
		}

		public function getTarget(): \OCP\Files\File {
			return $this->target;
		}

		/** @return array<string,mixed> */
		public function getTemplateFields(): array {
			return $this->templateFields;
		}
	}
}
