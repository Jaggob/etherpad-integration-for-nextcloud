<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCP\IL10N;
use OCP\IConfig;

class AppConfigService {
	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
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
		return $this->parseTrustedEmbedOrigins($this->getTrustedEmbedOriginsRaw());
	}

	/**
	 * @throws AdminValidationException
	 */
	public function normalizeTrustedEmbedOrigins(string $rawOrigins): string {
		return implode("\n", $this->parseTrustedEmbedOrigins($rawOrigins, true));
	}

	/**
	 * @return list<string>
	 * @throws AdminValidationException
	 */
	private function parseTrustedEmbedOrigins(string $rawOrigins, bool $throwOnInvalid = false): array {
		$tokens = preg_split('/[\r\n,;]+/', trim($rawOrigins)) ?: [];
		$normalized = [];

		foreach ($tokens as $token) {
			$entry = trim($token);
			if ($entry === '') {
				continue;
			}

			$parts = parse_url($entry);
			if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
				if ($throwOnInvalid) {
					throw new AdminValidationException(
						'trusted_embed_origins',
						$this->l10n->t('Trusted embed origins must be absolute origins: {origin}', ['origin' => $token])
					);
				}
				continue;
			}
			if (isset($parts['path']) && trim((string)$parts['path'], '/') !== '') {
				if ($throwOnInvalid) {
					throw new AdminValidationException(
						'trusted_embed_origins',
						$this->l10n->t('Trusted embed origins must not include a path: {origin}', ['origin' => $token])
					);
				}
				continue;
			}
			if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
				if ($throwOnInvalid) {
					throw new AdminValidationException(
						'trusted_embed_origins',
						$this->l10n->t('Trusted embed origins must not include credentials, query, or fragment: {origin}', ['origin' => $token])
					);
				}
				continue;
			}

			$scheme = strtolower((string)$parts['scheme']);
			if ($scheme !== 'https') {
				if ($throwOnInvalid) {
					throw new AdminValidationException(
						'trusted_embed_origins',
						$this->l10n->t('Trusted embed origins must use https: {origin}', ['origin' => $token])
					);
				}
				continue;
			}

			$origin = $scheme . '://' . strtolower((string)$parts['host']);
			if (isset($parts['port']) && (int)$parts['port'] > 0) {
				$origin .= ':' . (int)$parts['port'];
			}
			$normalized[$origin] = true;
		}

		return array_keys($normalized);
	}
}
