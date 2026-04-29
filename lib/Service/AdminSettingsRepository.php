<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\IConfig;

class AdminSettingsRepository {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function getStoredSettings(): StoredAdminSettings {
		return new StoredAdminSettings(
			trim((string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', '')),
			(string)$this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain', ''),
			(string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes',
			(string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes',
			(string)$this->config->getAppValue(Application::APP_ID, 'trusted_embed_origins', ''),
		);
	}

	public function persist(ValidatedAdminSettings $settings): void {
		$this->config->setAppValue(Application::APP_ID, 'etherpad_host', $settings->etherpadHost);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_api_host', $settings->etherpadApiHost);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain', $settings->etherpadCookieDomain);
		$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain_configured', 'yes');
		if ($settings->etherpadApiKey !== null) {
			$this->config->setAppValue(Application::APP_ID, 'etherpad_api_key', $settings->etherpadApiKey);
		}
		$this->config->setAppValue(Application::APP_ID, 'etherpad_api_version', $settings->etherpadApiVersion);
		$this->config->setAppValue(Application::APP_ID, 'sync_interval_seconds', (string)$settings->syncIntervalSeconds);
		$this->config->setAppValue(Application::APP_ID, 'delete_on_trash', $settings->deleteOnTrash ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'allow_external_pads', $settings->allowExternalPads ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'external_pad_allowlist', $settings->externalPadAllowlist);
		$this->config->setAppValue(Application::APP_ID, 'trusted_embed_origins', $settings->trustedEmbedOrigins);
	}

	public function hasApiKey(): bool {
		return trim((string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', '')) !== '';
	}
}
