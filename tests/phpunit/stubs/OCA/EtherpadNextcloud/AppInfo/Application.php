<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\AppInfo;

if (!class_exists(Application::class, false)) {
	class Application {
		public const APP_ID = 'etherpad_nextcloud';
	}
}
