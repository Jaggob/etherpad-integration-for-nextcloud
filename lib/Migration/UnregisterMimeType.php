<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class UnregisterMimeType implements IRepairStep {
	private const MIME = 'application/x-etherpad-nextcloud';
	private const MIME_ALIAS = 'etherpad-nextcloud-pad';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getName(): string {
		return 'Unregister MIME type for .pad files';
	}

	public function run(IOutput $output): void {
		$configDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
		if ($configDir === '') {
			return;
		}
		$configDir = dirname($configDir) . '/config/';

		$mimeToExt = [self::MIME => self::MIME_ALIAS];
		$extToMime = ['pad' => [self::MIME]];
		$this->removeFromJsonFile($configDir . 'mimetypealiases.json', $mimeToExt);
		$this->removeFromJsonFile($configDir . 'mimetypemapping.json', $extToMime);
	}

	private function removeFromJsonFile(string $file, array $mappings): void {
		if (!is_file($file)) {
			return;
		}
		$decoded = json_decode((string)file_get_contents($file), true);
		if (!is_array($decoded)) {
			return;
		}

		foreach ($mappings as $key => $value) {
			if (is_array($value) && isset($decoded[$key]) && is_array($decoded[$key])) {
				$decoded[$key] = array_values(array_filter($decoded[$key], fn ($item) => !in_array($item, $value, true)));
				if ($decoded[$key] === []) {
					unset($decoded[$key]);
				}
				continue;
			}
			unset($decoded[$key]);
		}

		file_put_contents($file, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}
}
