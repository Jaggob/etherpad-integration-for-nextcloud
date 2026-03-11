<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\AdminController;
use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
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
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new AdminController(
			'etherpad_nextcloud',
			$request,
			$this->createMock(IConfig::class),
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->buildL10n(),
			$this->createMock(EtherpadClient::class),
			$this->createMock(PendingDeleteRetryService::class),
			$this->createMock(ConsistencyCheckService::class),
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->saveSettings();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testSaveSettingsReturnsForbiddenForNonAdminUser(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('editor');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->once())->method('isAdmin')->with('editor')->willReturn(false);

		$controller = new AdminController(
			'etherpad_nextcloud',
			$request,
			$this->createMock(IConfig::class),
			$userSession,
			$groupManager,
			$this->buildL10n(),
			$this->createMock(EtherpadClient::class),
			$this->createMock(PendingDeleteRetryService::class),
			$this->createMock(ConsistencyCheckService::class),
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->saveSettings();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse((bool)$response->getData()['ok']);
	}

	public function testHealthCheckReturnsApiAndPendingDeleteMetrics(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'etherpad_host' => 'https://pad.example.test',
			'etherpad_api_host' => 'https://pad-api.internal',
			'etherpad_api_key' => 'new-api-key',
			'etherpad_api_version' => '1.3.0',
			'sync_interval_seconds' => 120,
			'delete_on_trash' => true,
			'allow_external_pads' => true,
			'external_pad_allowlist' => '',
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'delete_on_trash') {
					return 'yes';
				}
				if ($appName === 'etherpad_nextcloud' && $key === 'allow_external_pads') {
					return 'yes';
				}
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_api_key') {
					return 'existing-key';
				}
				return $default;
			}
		);

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('healthCheck')
			->with('https://pad-api.internal', 'new-api-key', '1.3.0')
			->willReturn(['pad_count' => 72]);

		$pendingDeleteRetry = $this->createMock(PendingDeleteRetryService::class);
		$pendingDeleteRetry->method('countPendingDeletes')->willReturn(3);
		$pendingDeleteRetry->method('countTrashedWithoutFile')->willReturn(1);

		$controller = new AdminController(
			'etherpad_nextcloud',
			$request,
			$config,
			$userSession,
			$groupManager,
			$this->buildL10n(),
			$etherpad,
			$pendingDeleteRetry,
			$this->createMock(ConsistencyCheckService::class),
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->healthCheck();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$data['ok']);
		$this->assertSame(72, $data['pad_count']);
		$this->assertSame(3, $data['pending_delete_count']);
		$this->assertSame(1, $data['trashed_without_file_count']);
		$this->assertSame('https://pad-api.internal/api/1.3.0/listAllPads', $data['target']);
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
