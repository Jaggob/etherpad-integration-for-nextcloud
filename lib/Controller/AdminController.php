<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\AdminPermissionRequiredException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class AdminController extends Controller {
	private const CONSISTENCY_FRONTMATTER_SCAN_LIMIT = 1500;
	private const CONSISTENCY_SAMPLE_LIMIT = 25;
	private const CONSISTENCY_FRONTMATTER_CHUNK_SIZE = 200;
	private const CONSISTENCY_FRONTMATTER_TIME_BUDGET_MS = 3000;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private AdminSettingsValidator $settingsValidator,
		private AdminSettingsRepository $settingsRepository,
		private EtherpadHealthCheckService $healthCheckService,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private ConsistencyCheckService $consistencyCheckService,
		private AdminControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	public function saveSettings(): DataResponse {
		return $this->errors->run(
			function (): ValidatedAdminSettings {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForSave(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				$this->settingsRepository->persist($settings);
				return $settings;
			},
			fn(ValidatedAdminSettings $settings): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Settings saved.'),
				'api_version' => $settings->etherpadApiVersion,
				'has_api_key' => $this->settingsRepository->hasApiKey(),
			]),
			[
				'generic' => $this->l10n->t('Failed to save settings.'),
				'log_message' => 'Saving Etherpad settings failed',
			],
		);
	}

	public function healthCheck(): DataResponse {
		return $this->errors->run(
			function (): HealthCheckResult {
				$this->requireAdmin();
				$settings = $this->settingsValidator->validateForHealthCheck(
					$this->readJsonPayload(),
					$this->settingsRepository->getStoredSettings(),
				);
				return $this->healthCheckService->check($settings);
			},
			fn(HealthCheckResult $result): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Health check successful.'),
				'host' => $result->host,
				'api_host' => $result->apiHost,
				'api_version' => $result->apiVersion,
				'pad_count' => $result->padCount,
				'latency_ms' => $result->latencyMs,
				'target' => $result->target,
				'pending_delete_count' => $result->pendingDeleteCount,
				'trashed_without_file_count' => $result->trashedWithoutFileCount,
			]),
			[
				'generic' => $this->l10n->t('Health check failed.'),
				'log_message' => 'Etherpad health check failed',
			],
		);
	}

	public function retryPendingDeletes(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				return $this->pendingDeleteRetryService->retry(500);
			},
			fn(array $result): DataResponse => new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Lifecycle delete retry finished.'),
				'attempted' => $result['attempted'],
				'resolved' => $result['resolved'],
				'failed' => $result['failed'],
				'remaining' => $result['remaining'],
				'trashed_attempted' => $result['trashed_attempted'],
				'trashed_resolved' => $result['trashed_resolved'],
				'trashed_failed' => $result['trashed_failed'],
				'trashed_without_file_remaining' => $result['trashed_without_file_remaining'],
			]),
			[
				'generic' => $this->l10n->t('Pending delete retry failed.'),
				'log_message' => 'Pending delete retry failed',
			],
		);
	}

	public function consistencyCheck(): DataResponse {
		return $this->errors->run(
			function (): array {
				$this->requireAdmin();
				return $this->consistencyCheckService->run(
					self::CONSISTENCY_FRONTMATTER_SCAN_LIMIT,
					self::CONSISTENCY_SAMPLE_LIMIT,
					self::CONSISTENCY_FRONTMATTER_CHUNK_SIZE,
					self::CONSISTENCY_FRONTMATTER_TIME_BUDGET_MS,
				);
			},
			function (array $result): DataResponse {
				$issues = (int)$result['binding_without_file_count']
					+ (int)$result['file_without_binding_count']
					+ (int)$result['invalid_frontmatter_count'];
				$timeBudgetExceeded = (bool)($result['frontmatter_time_budget_exceeded'] ?? false);
				$scanLimitReached = (bool)($result['frontmatter_scan_limit_reached'] ?? false);
				$isPartial = $timeBudgetExceeded || $scanLimitReached;
				$message = $issues > 0
					? $this->l10n->t('Consistency check finished with issues.')
					: $this->l10n->t('Consistency check successful. No issues found.');
				if ($isPartial) {
					$message .= ' ' . $this->l10n->t('Frontmatter validation result is partial (scan limit/time budget reached).');
				}

				return new DataResponse([
					'ok' => true,
					'message' => $message,
					'binding_without_file_count' => (int)$result['binding_without_file_count'],
					'file_without_binding_count' => (int)$result['file_without_binding_count'],
					'invalid_frontmatter_count' => (int)$result['invalid_frontmatter_count'],
					'frontmatter_scanned' => (int)$result['frontmatter_scanned'],
					'frontmatter_skipped' => (int)$result['frontmatter_skipped'],
					'frontmatter_scan_limit_reached' => $scanLimitReached,
					'frontmatter_time_budget_exceeded' => $timeBudgetExceeded,
					'frontmatter_time_budget_ms' => (int)($result['frontmatter_time_budget_ms'] ?? 0),
					'frontmatter_chunk_size' => (int)($result['frontmatter_chunk_size'] ?? 0),
					'samples' => $result['samples'],
				]);
			},
			[
				'generic' => $this->l10n->t('Consistency check failed.'),
				'log_message' => 'Consistency check failed',
			],
		);
	}

	public function setTestFault(): DataResponse {
		return $this->errors->run(
			function (): string {
				$this->requireAdmin();
				if (!$this->config->getSystemValueBool('debug', false)) {
					throw new AdminDebugModeRequiredException();
				}

				$payload = $this->readJsonPayload();
				$fault = trim((string)($payload['fault'] ?? ''));
				$allowed = LifecycleService::getSupportedTestFaults();
				if ($fault !== '' && !in_array($fault, $allowed, true)) {
					throw new UnsupportedTestFaultException($allowed);
				}

				$this->config->setAppValue(Application::APP_ID, 'test_fault', $fault);
				return $fault;
			},
			fn(string $fault): DataResponse => new DataResponse([
				'ok' => true,
				'fault' => $fault,
				'message' => $fault === ''
					? $this->l10n->t('Test fault cleared.')
					: $this->l10n->t('Test fault set: {fault}', ['fault' => $fault]),
			]),
			[
				'generic' => $this->l10n->t('Failed to update test fault.'),
				'log_message' => 'Updating test fault failed',
			],
		);
	}

	/** @return array<string,mixed> */
	private function readJsonPayload(): array {
		$params = $this->request->getParams();
		return is_array($params) ? $params : [];
	}

	private function requireAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new AdminPermissionRequiredException('Admin permissions required.');
		}
	}
}
