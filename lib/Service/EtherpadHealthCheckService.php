<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IL10N;

class EtherpadHealthCheckService {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private IL10N $l10n,
	) {
	}

	public function check(ValidatedAdminSettings $settings): HealthCheckResult {
		$startedAt = $this->now();
		try {
			$result = $this->etherpadClient->healthCheck(
				$settings->etherpadApiHost,
				$settings->effectiveApiKey,
				$settings->etherpadApiVersion,
			);
		} catch (EtherpadClientException $e) {
			$detail = $e->getMessage();
			if ($this->isLikelyAuthMethodMismatch($e)) {
				$detail .= ' ' . $this->l10n->t('Hint: In Etherpad settings.json set "authenticationMethod": "apikey".');
			}
			throw new AdminHealthCheckException(
				$this->l10n->t('Health check failed: {detail}', ['detail' => $detail]),
				0,
				$e,
			);
		}

		return new HealthCheckResult(
			$settings->etherpadHost,
			$settings->etherpadApiHost,
			$settings->etherpadApiVersion,
			(int)($result['pad_count'] ?? 0),
			(int)round(($this->now() - $startedAt) * 1000),
			rtrim($settings->etherpadApiHost, '/') . '/api/' . $settings->etherpadApiVersion . '/listAllPads',
			$this->pendingDeleteRetryService->countPendingDeletes(),
			$this->pendingDeleteRetryService->countTrashedWithoutFile(),
		);
	}

	private function isLikelyAuthMethodMismatch(EtherpadClientException $e): bool {
		// Etherpad returns these strings for API auth failures in supported versions.
		// If upstream wording changes, the health check still fails; only the hint drops.
		$message = strtolower($e->getMessage());
		return str_contains($message, 'no or wrong api key')
			|| str_contains($message, 'wrong api key')
			|| str_contains($message, 'invalid apikey');
	}

	protected function now(): float {
		return microtime(true);
	}
}
