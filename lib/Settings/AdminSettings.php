<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Settings;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private AppConfigService $appConfigService,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'admin-settings');
		Util::addScript(Application::APP_ID, 'admin-settings');

		$etherpadHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_host', '');
		$etherpadApiHost = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_host', '');
		$apiVersion = (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_version', '1.2.15');
		$syncInterval = (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval_seconds', '120');
		if ($syncInterval < 5) {
			$syncInterval = 5;
		}
		if ($syncInterval > 3600) {
			$syncInterval = 3600;
		}

		return new TemplateResponse(Application::APP_ID, 'admin-settings', [
			'etherpad_host' => $etherpadHost,
			'etherpad_api_host' => $etherpadApiHost,
			'etherpad_api_version' => $apiVersion,
			'sync_interval_seconds' => $syncInterval,
			'delete_on_trash' => (string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes',
			'allow_external_pads' => (string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'yes') === 'yes',
			'external_pad_allowlist' => (string)$this->config->getAppValue(Application::APP_ID, 'external_pad_allowlist', ''),
			'trusted_embed_origins' => $this->appConfigService->getTrustedEmbedOriginsRaw(),
			'has_api_key' => (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', '') !== '',
			'save_settings_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.saveSettings'),
			'health_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.healthCheck'),
			'consistency_check_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.consistencyCheck'),
			'retry_pending_deletes_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.admin.retryPendingDeletes'),
			'l10n' => [
				'section_title' => $this->l10n->t('Pads'),
				'intro' => $this->l10n->t('Configure the Etherpad server and external pad security policy for the Etherpad Nextcloud app.'),
				'etherpad_base_url' => $this->l10n->t('Etherpad Base URL'),
				'etherpad_api_url' => $this->l10n->t('Etherpad API URL (optional)'),
				'etherpad_api_url_hint' => $this->l10n->t('Optional internal URL for server-side API calls. Leave empty to use Etherpad Base URL.'),
				'etherpad_api_key' => $this->l10n->t('Etherpad API key'),
				'detected_api_version' => $this->l10n->t('Detected API version:'),
				'copy_interval' => $this->l10n->t('Copy content to .pad file interval (seconds)'),
				'copy_interval_hint' => $this->l10n->t('Controls how often pad content is copied from Etherpad into the .pad file while the pad is open.'),
				'delete_on_trash' => $this->l10n->t('Delete pad on trash'),
				'delete_on_trash_hint' => $this->l10n->t('If enabled, moving a .pad file to trash triggers Etherpad delete.'),
				'allow_external_pads' => $this->l10n->t('Allow linking external public pads'),
				'external_allowlist' => $this->l10n->t('External host allowlist (optional)'),
				'external_allowlist_hint' => $this->l10n->t('Leave empty to allow all public hosts. HTTPS is always required.'),
				'trusted_embed_origins' => $this->l10n->t('Trusted embed origins (optional)'),
				'trusted_embed_origins_hint' => $this->l10n->t('Absolute https origins allowed to embed the /embed/by-id and /embed/create-by-parent routes. Leave empty to disable external embedding.'),
				'save_button' => $this->l10n->t('Save settings'),
				'health_button' => $this->l10n->t('Health check'),
				'consistency_button' => $this->l10n->t('Consistency check'),
				'retry_pending_button' => $this->l10n->t('Retry deferred deletes now'),
				'pending_delete_label' => $this->l10n->t('Pending Etherpad deletes'),
				'trashed_without_file_label' => $this->l10n->t('Trashed bindings without file'),
				'saving' => $this->l10n->t('Saving settings...'),
				'saved' => $this->l10n->t('Settings saved.'),
				'checking' => $this->l10n->t('Running health check...'),
				'consistency_running' => $this->l10n->t('Running consistency check...'),
				'health_ok' => $this->l10n->t('Health check successful.'),
				'consistency_ok' => $this->l10n->t('Consistency check successful.'),
				'request_failed' => $this->l10n->t('Request failed.'),
				'saving_failed' => $this->l10n->t('Saving settings failed.'),
				'health_failed' => $this->l10n->t('Health check failed.'),
				'consistency_failed' => $this->l10n->t('Consistency check failed.'),
				'retry_failed' => $this->l10n->t('Lifecycle delete retry failed.'),
			],
		]);
	}

	public function getSection(): string {
		return 'etherpad_nextcloud_pads';
	}

	public function getPriority(): int {
		return 10;
	}
}
