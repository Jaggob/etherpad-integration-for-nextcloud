<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PublicViewerController;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Files\File;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicViewerControllerTest extends TestCase {
	public function testOpenPadDataRejectsExternalProtectedMetadata(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('frontmatter');

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parsePadFile')
			->with('frontmatter')
			->willReturn([
				'frontmatter' => [
					'pad_id' => 'ext.123',
					'access_mode' => BindingService::ACCESS_PROTECTED,
					'pad_url' => 'https://remote.example.test/p/demo',
					'pad_origin' => 'https://remote.example.test',
					'remote_pad_id' => 'demo',
				],
			]);
		$padFileService->expects($this->once())
			->method('extractPadMetadata')
			->willReturn([
				'pad_id' => 'ext.123',
				'access_mode' => BindingService::ACCESS_PROTECTED,
				'pad_url' => 'https://remote.example.test/p/demo',
			]);
		$padFileService->expects($this->once())
			->method('isExternalFrontmatter')
			->with([
				'pad_id' => 'ext.123',
				'access_mode' => BindingService::ACCESS_PROTECTED,
				'pad_url' => 'https://remote.example.test/p/demo',
				'pad_origin' => 'https://remote.example.test',
				'remote_pad_id' => 'demo',
			], 'ext.123')
			->willReturn(true);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(42, 'ext.123', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('normalizeAndValidateExternalPublicPadUrl');

		$padSessionService = $this->createMock(PadSessionService::class);
		$padSessionService->expects($this->never())->method('createProtectedOpenContext');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn('');

		$controller = new PublicViewerController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$shareManager,
			new PathNormalizer(),
			$padFileService,
			$bindingService,
			$etherpadClient,
			$padSessionService,
			$urlGenerator,
			$this->createMock(ISession::class),
		);

		$response = $controller->openPadData('share-token');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Etherpad is currently unavailable for this shared pad.', $response->getData()['message']);
	}

	public function testPasswordProtectedShareRequiresAuthenticatedSession(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPassword')->willReturn('stored-password-hash');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$session = $this->createMock(ISession::class);
		$session->method('get')->with('public_link_authenticated_frontend')->willReturn('[]');

		$controller = $this->buildController($shareManager, session: $session);
		$controller->setToken('share-token');

		$this->assertTrue($controller->isValidToken());
		$this->assertFalse($controller->isAuthenticated());
	}

	public function testPasswordProtectedShareAcceptsMatchingPublicShareSession(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPassword')->willReturn('stored-password-hash');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$session = $this->createMock(ISession::class);
		$session->method('get')
			->with('public_link_authenticated_frontend')
			->willReturn('{"share-token":"stored-password-hash"}');

		$controller = $this->buildController($shareManager, session: $session);
		$controller->setToken('share-token');

		$this->assertTrue($controller->isValidToken());
		$this->assertTrue($controller->isAuthenticated());
	}

	public function testOpenPadDataRejectsShareWithoutReadPermission(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_UPDATE);
		$share->expects($this->never())->method('getNode');

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->once())
			->method('getShareByToken')
			->with('share-token')
			->willReturn($share);

		$response = $this->buildController($shareManager)->openPadData('share-token');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('This share link does not allow reading files.', $response->getData()['message']);
	}

	private function buildController(
		IManager $shareManager,
		?PadFileService $padFileService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
		?ISession $session = null,
	): PublicViewerController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn('');

		return new PublicViewerController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$shareManager,
			new PathNormalizer(),
			$padFileService ?? $this->createMock(PadFileService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			$urlGenerator,
			$session ?? $this->createMock(ISession::class),
		);
	}
}
