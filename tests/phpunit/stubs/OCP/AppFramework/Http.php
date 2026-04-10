<?php

declare(strict_types=1);

namespace OCP\AppFramework;

if (!class_exists(Http::class)) {
	class Http {
		public const STATUS_OK = 200;
		public const STATUS_BAD_REQUEST = 400;
		public const STATUS_UNAUTHORIZED = 401;
		public const STATUS_FORBIDDEN = 403;
		public const STATUS_NOT_FOUND = 404;
		public const STATUS_CONFLICT = 409;
		public const STATUS_INTERNAL_SERVER_ERROR = 500;
		public const STATUS_SERVICE_UNAVAILABLE = 503;
		public const STATUS_BAD_GATEWAY = 502;
	}
}
