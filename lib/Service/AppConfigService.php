<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\IConfig;

class AppConfigService {
	public function __construct(
		private IConfig $config,
		private TrustedEmbedOriginsNormalizer $trustedEmbedOriginsNormalizer,
	) {
	}

	public function getSyncIntervalSeconds(): int {
		$raw = (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_seconds', '120');
		if ($raw < 5) {
			return 5;
		}
		if ($raw > 3600) {
			return 3600;
		}
		return $raw;
	}

	public function getTrustedEmbedOriginsRaw(): string {
		return (string)$this->config->getAppValue(Application::APP_ID, 'trusted_embed_origins', '');
	}

	/**
	 * @return list<string>
	 */
	public function getTrustedEmbedOrigins(): array {
		return $this->trustedEmbedOriginsNormalizer->parse($this->getTrustedEmbedOriginsRaw());
	}

	public function normalizeTrustedEmbedOrigins(string $rawOrigins): string {
		return $this->trustedEmbedOriginsNormalizer->normalize($rawOrigins);
	}
}
