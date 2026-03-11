<?php

declare(strict_types=1);

namespace OCP\AppFramework;

use OCP\IRequest;

if (!class_exists(Controller::class)) {
	class Controller {
		public function __construct(
			protected string $appName,
			protected IRequest $request,
		) {
		}
	}
}
