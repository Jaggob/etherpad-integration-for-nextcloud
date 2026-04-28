<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private AppConfigService $appConfigService,
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private ConsistencyCheckService $consistencyCheckService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	public function saveSettings(): DataResponse {
		$authError = $this->requireAdmin();
		if ($authError !== null) {
			return $authError;
		}

		try {
			$payload = $this->readJsonPayload();
			$validated = $this->validateSettingsPayload($payload, false);

			$this->config->setAppValue(Application::APP_ID, 'etherpad_host', $validated['etherpad_host']);
			$this->config->setAppValue(Application::APP_ID, 'etherpad_api_host', $validated['etherpad_api_host']);
			$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain', $validated['etherpad_cookie_domain']);
			$this->config->setAppValue(Application::APP_ID, 'etherpad_cookie_domain_configured', 'yes');
			if ($validated['etherpad_api_key'] !== null) {
				$this->config->setAppValue(Application::APP_ID, 'etherpad_api_key', $validated['etherpad_api_key']);
			}
			$this->config->setAppValue(Application::APP_ID, 'etherpad_api_version', $validated['etherpad_api_version']);
			$this->config->setAppValue(Application::APP_ID, 'sync_interval_seconds', (string)$validated['sync_interval_seconds']);
			$this->config->setAppValue(
				Application::APP_ID,
				'delete_on_trash',
				$validated['delete_on_trash'] ? 'yes' : 'no',
			);
			$this->config->setAppValue(
				Application::APP_ID,
				'allow_external_pads',
				$validated['allow_external_pads'] ? 'yes' : 'no',
			);
			$this->config->setAppValue(Application::APP_ID, 'external_pad_allowlist', $validated['external_pad_allowlist']);
			$this->config->setAppValue(Application::APP_ID, 'trusted_embed_origins', $validated['trusted_embed_origins']);

			return new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Settings saved.'),
				'api_version' => $validated['etherpad_api_version'],
				'has_api_key' => (string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', '') !== '',
			]);
		} catch (AdminValidationException $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
				'field' => $e->getField(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Saving Etherpad settings failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Failed to save settings.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function healthCheck(): DataResponse {
		$authError = $this->requireAdmin();
		if ($authError !== null) {
			return $authError;
		}

		try {
			$payload = $this->readJsonPayload();
			$validated = $this->validateSettingsPayload($payload, true);
			$startedAt = microtime(true);
			$result = $this->etherpadClient->healthCheck(
				$validated['etherpad_api_host'],
				$validated['effective_api_key'],
				$validated['etherpad_api_version'],
			);
			$latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
			$target = rtrim($validated['etherpad_api_host'], '/') . '/api/' . $validated['etherpad_api_version'] . '/listAllPads';

			return new DataResponse([
				'ok' => true,
				'message' => $this->l10n->t('Health check successful.'),
				'host' => $validated['etherpad_host'],
				'api_host' => $validated['etherpad_api_host'],
				'api_version' => $validated['etherpad_api_version'],
				'pad_count' => $result['pad_count'],
				'latency_ms' => $latencyMs,
				'target' => $target,
				'pending_delete_count' => $this->pendingDeleteRetryService->countPendingDeletes(),
				'trashed_without_file_count' => $this->pendingDeleteRetryService->countTrashedWithoutFile(),
			]);
		} catch (AdminValidationException $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
				'field' => $e->getField(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([
				'ok' => false,
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (EtherpadClientException $e) {
			$detail = $e->getMessage();
			if ($this->isLikelyAuthMethodMismatch($e)) {
				$detail .= ' ' . $this->l10n->t('Hint: In Etherpad settings.json set "authenticationMethod": "apikey".');
			}
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Health check failed: {detail}', ['detail' => $detail]),
			], Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('Etherpad health check failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Health check failed.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function retryPendingDeletes(): DataResponse {
		$authError = $this->requireAdmin();
		if ($authError !== null) {
			return $authError;
		}

		try {
			$result = $this->pendingDeleteRetryService->retry(500);
			return new DataResponse([
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
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Pending delete retry failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Pending delete retry failed.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function consistencyCheck(): DataResponse {
		$authError = $this->requireAdmin();
		if ($authError !== null) {
			return $authError;
		}

		try {
			$result = $this->consistencyCheckService->run(1500, 25, 200, 3000);
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
		} catch (\Throwable $e) {
			$this->logger->error('Consistency check failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Consistency check failed.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function setTestFault(): DataResponse {
		$authError = $this->requireAdmin();
		if ($authError !== null) {
			return $authError;
		}
		if (!$this->config->getSystemValueBool('debug', false)) {
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Test faults are available only when Nextcloud debug mode is enabled.'),
			], Http::STATUS_FORBIDDEN);
		}

		$payload = $this->readJsonPayload();
		$fault = trim((string)($payload['fault'] ?? ''));
		$allowed = LifecycleService::getSupportedTestFaults();
		if ($fault !== '' && !in_array($fault, $allowed, true)) {
			return new DataResponse([
				'ok' => false,
				'message' => $this->l10n->t('Unsupported test fault.'),
				'supported_faults' => $allowed,
			], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setAppValue(Application::APP_ID, 'test_fault', $fault);
		return new DataResponse([
			'ok' => true,
			'fault' => $fault,
			'message' => $fault === ''
				? $this->l10n->t('Test fault cleared.')
				: $this->l10n->t('Test fault set: {fault}', ['fault' => $fault]),
		]);
	}

	/** @return array<string,mixed> */
	private function readJsonPayload(): array {
		$params = $this->request->getParams();
		return is_array($params) ? $params : [];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{
	 *   etherpad_host: string,
	 *   etherpad_api_host: string,
	 *   etherpad_cookie_domain: string,
	 *   etherpad_api_key: ?string,
	 *   effective_api_key: string,
	 *   etherpad_api_version: string,
	 *   sync_interval_seconds: int,
	 *   delete_on_trash: bool,
	 *   allow_external_pads: bool,
	 *   external_pad_allowlist: string,
	 *   trusted_embed_origins: string
	 * }
	 */
	private function validateSettingsPayload(array $payload, bool $forHealthCheck): array {
		$host = $this->normalizeEtherpadHost((string)($payload['etherpad_host'] ?? ''));
		$apiHost = $this->normalizeEtherpadApiHost((string)($payload['etherpad_api_host'] ?? ''), $host);
		$cookieDomain = $this->normalizeCookieDomain(
			(string)($payload['etherpad_cookie_domain'] ?? $this->config->getAppValue(Application::APP_ID, 'etherpad_cookie_domain', ''))
		);
		$syncIntervalSeconds = $this->normalizeSyncInterval($payload['sync_interval_seconds'] ?? 120);
		$deleteOnTrash = $this->toBool(
			$payload['delete_on_trash']
				?? ((string)$this->config->getAppValue(Application::APP_ID, 'delete_on_trash', 'yes') === 'yes')
		);
		$allowExternalPads = $this->toBool(
			$payload['allow_external_pads']
				?? ((string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes')
		);
		$externalAllowlist = $this->normalizeAllowlist((string)($payload['external_pad_allowlist'] ?? ''));
		$trustedEmbedOrigins = $this->appConfigService->normalizeTrustedEmbedOrigins(
			(string)($payload['trusted_embed_origins'] ?? $this->appConfigService->getTrustedEmbedOriginsRaw())
		);

		$rawApiKey = trim((string)($payload['etherpad_api_key'] ?? ''));
		$storedApiKey = trim((string)$this->config->getAppValue(Application::APP_ID, 'etherpad_api_key', ''));
		$apiKeyToStore = $rawApiKey === '' ? null : $rawApiKey;
		$effectiveApiKey = $rawApiKey !== '' ? $rawApiKey : $storedApiKey;

		if ($effectiveApiKey === '') {
			throw new AdminValidationException('etherpad_api_key', $this->l10n->t('Etherpad API key is required.'));
		}
		if (!$forHealthCheck && $rawApiKey === '' && $storedApiKey === '') {
			throw new AdminValidationException('etherpad_api_key', $this->l10n->t('Etherpad API key is required.'));
		}
		$apiVersion = $this->resolveApiVersion((string)($payload['etherpad_api_version'] ?? ''), $apiHost);

		return [
			'etherpad_host' => $host,
			'etherpad_api_host' => $apiHost,
			'etherpad_cookie_domain' => $cookieDomain,
			'etherpad_api_key' => $apiKeyToStore,
			'effective_api_key' => $effectiveApiKey,
			'etherpad_api_version' => $apiVersion,
			'sync_interval_seconds' => $syncIntervalSeconds,
			'delete_on_trash' => $deleteOnTrash,
			'allow_external_pads' => $allowExternalPads,
			'external_pad_allowlist' => $externalAllowlist,
			'trusted_embed_origins' => $trustedEmbedOrigins,
		];
	}

	private function normalizeEtherpadHost(string $rawHost): string {
		$trimmed = trim($rawHost);
		if ($trimmed === '') {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL is required.'));
		}
		if (preg_match('#^https://#i', $trimmed) !== 1) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL must use https.'));
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Invalid Etherpad Base URL.'));
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL must not include query or fragment.'));
		}

		$scheme = strtolower((string)$parts['scheme']);
		$host = strtolower((string)$parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : 0;
		$path = trim((string)($parts['path'] ?? ''));

		$normalized = $scheme . '://' . $host;
		if ($port > 0) {
			$normalized .= ':' . $port;
		}
		if ($path !== '') {
			$normalized .= '/' . ltrim($path, '/');
			$normalized = rtrim($normalized, '/');
		}

		return $normalized;
	}

	private function normalizeEtherpadApiHost(string $rawHost, string $fallbackPublicHost): string {
		$trimmed = trim($rawHost);
		if ($trimmed === '') {
			return $fallbackPublicHost;
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Invalid Etherpad API URL.'));
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Etherpad API URL must not include query or fragment.'));
		}

		$scheme = strtolower((string)$parts['scheme']);
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Etherpad API URL must use http or https.'));
		}
		$host = strtolower((string)$parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : 0;
		$path = trim((string)($parts['path'] ?? ''));

		$normalized = $scheme . '://' . $host;
		if ($port > 0) {
			$normalized .= ':' . $port;
		}
		if ($path !== '') {
			$normalized .= '/' . ltrim($path, '/');
			$normalized = rtrim($normalized, '/');
		}

		return $normalized;
	}

	private function normalizeCookieDomain(string $rawDomain): string {
		$domain = strtolower(trim($rawDomain));
		if ($domain === '') {
			return '';
		}

		if (str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')) {
			throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a hostname, not a URL.'));
		}

		$isParentDomain = str_starts_with($domain, '.');
		$host = ltrim($domain, '.');
		if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
			throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a valid shared hostname.'));
		}

		foreach (explode('.', $host) as $label) {
			if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
				throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a valid shared hostname.'));
			}
		}

		return ($isParentDomain ? '.' : '') . $host;
	}

	private function normalizeApiVersion(string $rawVersion): string {
		$version = trim($rawVersion);
		if ($version === '') {
			return '1.2.15';
		}
		if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
			throw new AdminValidationException('etherpad_api_version', $this->l10n->t('Invalid Etherpad API version format.'));
		}
		return $version;
	}

	private function normalizeSyncInterval(mixed $value): int {
		$interval = (int)$value;
		if ($interval < 5 || $interval > 3600) {
			throw new AdminValidationException('sync_interval_seconds', $this->l10n->t('Sync interval must be between 5 and 3600 seconds.'));
		}
		return $interval;
	}

	private function normalizeAllowlist(string $rawAllowlist): string {
		$tokens = preg_split('/[\s,;]+/', trim($rawAllowlist)) ?: [];
		$normalized = [];
		foreach ($tokens as $token) {
			$entry = trim($token);
			if ($entry === '') {
				continue;
			}

			if (preg_match('#^https?://#i', $entry) === 1) {
				$parts = parse_url($entry);
				$scheme = strtolower((string)($parts['scheme'] ?? ''));
				$host = strtolower((string)($parts['host'] ?? ''));
				$port = isset($parts['port']) ? (int)$parts['port'] : 443;
				$path = (string)($parts['path'] ?? '');
				if ($scheme !== 'https' || $host === '' || $port <= 0 || $port > 65535 || ($path !== '' && $path !== '/')
					|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
				) {
					throw new AdminValidationException(
						'external_pad_allowlist',
						$this->l10n->t('External allowlist URL must use https: {host}', ['host' => $token])
					);
				}
				$entry = $this->normalizeAllowlistHost($host, $token);
				$normalized[($port === 443 ? 'https://' . $entry : 'https://' . $entry . ':' . $port)] = true;
				continue;
			}

			$normalized[$this->normalizeAllowlistHost($entry, $token)] = true;
		}

		return implode("\n", array_keys($normalized));
	}

	private function normalizeAllowlistHost(string $rawHost, string $sourceToken): string {
		$host = strtolower(trim($rawHost, ". \t\n\r\0\x0B"));
		if ($host === '' || str_contains($host, '..') || str_starts_with($host, '-') || str_ends_with($host, '-')) {
			throw new AdminValidationException(
				'external_pad_allowlist',
				$this->l10n->t('External allowlist contains invalid host: {host}', ['host' => $sourceToken])
			);
		}
		if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
			throw new AdminValidationException(
				'external_pad_allowlist',
				$this->l10n->t('External allowlist contains invalid host: {host}', ['host' => $sourceToken])
			);
		}
		return $host;
	}

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return (int)$value !== 0;
		}
		$normalized = strtolower(trim((string)$value));
		return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
	}

	private function resolveApiVersion(string $rawVersion, string $host): string {
		$manual = trim($rawVersion);
		if ($manual !== '') {
			return $this->normalizeApiVersion($manual);
		}

		try {
			return $this->normalizeApiVersion($this->etherpadClient->detectApiVersion($host));
		} catch (\Throwable) {
			return '1.2.15';
		}
	}

	private function isLikelyAuthMethodMismatch(EtherpadClientException $e): bool {
		$message = strtolower($e->getMessage());
		return str_contains($message, 'no or wrong api key')
			|| str_contains($message, 'wrong api key')
			|| str_contains($message, 'invalid apikey');
	}

	private function requireAdmin(): ?DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['ok' => false, 'message' => $this->l10n->t('Authentication required.')], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->groupManager->isAdmin($user->getUID())) {
			return new DataResponse(['ok' => false, 'message' => $this->l10n->t('Admin permissions required.')], Http::STATUS_FORBIDDEN);
		}
		return null;
	}
}
