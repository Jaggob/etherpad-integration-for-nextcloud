<?php

declare(strict_types=1);

namespace OCP\Share\Exceptions;

if (!class_exists(ShareNotFound::class)) {
	class ShareNotFound extends \Exception {
	}
}
