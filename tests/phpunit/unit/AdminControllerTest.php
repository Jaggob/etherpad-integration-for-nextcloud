<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\AdminController;
use OCA\EtherpadNextcloud\Controller\AdminControllerErrorMapper;
use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\AdminSettingsValidator;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\HealthCheckResult;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminControllerTest extends TestCase {
	public function testSaveSettingsReturnsUnauthorizedWhenNoUserSession(): void {
		$response = $this->buildController(userSession: $this->userSession(null))->saveSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSaveSettingsReturnsForbiddenForNonAdminUser(): void {
		$response = $this->buildController(groupManager: $this->adminGroup(false))->saveSettings();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSaveSettingsPersistsValidatedSettings(): void {
		$request = $this->request(['etherpad_host' => 'https://pad.example.test']);
		$stored = new StoredAdminSettings('old-key', '', true, false, '');
		$validated = $this->validatedSettings();

		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getStoredSettings')->willReturn($stored);
		$repository->expects($this->once())->method('persist')->with($validated);
		$repository->method('hasApiKey')->willReturn(true);

		$validator = $this->createMock(AdminSettingsValidator::class);
		$validator->expects($this->once())
			->method('validateForSave')
			->with(['etherpad_host' => 'https://pad.example.test'], $stored)
			->willReturn($validated);

		$response = $this->buildController($request, validator: $validator, repository: $repository)->saveSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
		$this->assertSame('1.3.0', $response->getData()['api_version']);
		$this->assertTrue((bool)$response->getData()['has_api_key']);
	}

	public function testHealthCheckReturnsApiAndPendingDeleteMetrics(): void {
		$request = $this->request(['etherpad_host' => 'https://pad.example.test']);
		$stored = new StoredAdminSettings('existing-key', '', true, false, '');
		$validated = $this->validatedSettings();

		$repository = $this->createMock(AdminSettingsRepository::class);
		$repository->method('getStoredSettings')->willReturn($stored);

		$validator = $this->createMock(AdminSettingsValidator::class);
		$validator->expects($this->once())
			->method('validateForHealthCheck')
			->with(['etherpad_host' => 'https://pad.example.test'], $stored)
			->willReturn($validated);

		$health = $this->createMock(EtherpadHealthCheckService::class);
		$health->expects($this->once())
			->method('check')
			->with($validated)
			->willReturn(new HealthCheckResult(
				'https://pad.example.test',
				'https://pad-api.internal',
				'1.3.0',
				72,
				123,
				'https://pad-api.internal/api/1.3.0/listAllPads',
				3,
				1,
			));

		$response = $this->buildController($request, validator: $validator, repository: $repository, healthCheck: $health)->healthCheck();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$data['ok']);
		$this->assertSame(72, $data['pad_count']);
		$this->assertSame(3, $data['pending_delete_count']);
		$this->assertSame(1, $data['trashed_without_file_count']);
		$this->assertSame('https://pad-api.internal/api/1.3.0/listAllPads', $data['target']);
	}

	private function buildController(
		?IRequest $request = null,
		?IConfig $config = null,
		?IUserSession $userSession = null,
		?IGroupManager $groupManager = null,
		?AdminSettingsValidator $validator = null,
		?AdminSettingsRepository $repository = null,
		?EtherpadHealthCheckService $healthCheck = null,
	): AdminController {
		$l10n = $this->buildL10n();
		$logger = $this->createMock(LoggerInterface::class);
		return new AdminController(
			'etherpad_nextcloud',
			$request ?? $this->request([]),
			$config ?? $this->createMock(IConfig::class),
			$userSession ?? $this->userSession('admin'),
			$groupManager ?? $this->adminGroup(true),
			$l10n,
			$validator ?? $this->createMock(AdminSettingsValidator::class),
			$repository ?? $this->createMock(AdminSettingsRepository::class),
			$healthCheck ?? $this->createMock(EtherpadHealthCheckService::class),
			$this->createMock(PendingDeleteRetryService::class),
			$this->createMock(ConsistencyCheckService::class),
			new AdminControllerErrorMapper($l10n, $logger),
		);
	}

	/** @param array<string,mixed> $payload */
	private function request(array $payload): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($payload);
		return $request;
	}

	private function userSession(?string $uid): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
			return $userSession;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession->method('getUser')->willReturn($user);
		return $userSession;
	}

	private function adminGroup(bool $isAdmin): IGroupManager {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		return $groupManager;
	}

	private function validatedSettings(): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.internal',
			'.example.test',
			'new-api-key',
			'new-api-key',
			'1.3.0',
			120,
			true,
			true,
			'pad.example.test',
			'',
		);
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
