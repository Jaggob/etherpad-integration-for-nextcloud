<?php

declare(strict_types=1);

/**
 * Psalm-only autoloader for the Nextcloud public API.
 *
 * nextcloud/ocp ships the OCP/NCU type signatures but registers no composer
 * autoload, so neither Composer nor Psalm can locate them. We register a
 * dedicated SPL autoloader here (referenced via <autoloader> in psalm.xml) so
 * Psalm can find and scan the OCP/NCU definitions for type information, without
 * touching the runtime/PHPUnit autoloader (which uses its own local stubs).
 */

// Define the internal/external classes the OCP type files reference but that
// nextcloud/ocp does not ship, so autoloading those OCP files won't fatal.
require_once __DIR__ . '/missing-class-stubs.php';

spl_autoload_register(static function (string $class): void {
	foreach (['OCP', 'NCU'] as $top) {
		if ($class === $top || str_starts_with($class, $top . '\\')) {
			$file = __DIR__ . '/../../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});
