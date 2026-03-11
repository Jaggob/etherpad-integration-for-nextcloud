<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadController;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadControllerTest extends TestCase {
	public function testCreateReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->create('/Test.pad');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required.', $response->getData()['message']);
	}

	public function testOpenByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->openById(0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	public function testSyncByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->syncById(-5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file ID.', $response->getData()['message']);
	}

	private function buildController(IRequest $request, IUserSession $userSession): PadController {
		return new PadController(
			'etherpad_nextcloud',
			$request,
			$this->createMock(IURLGenerator::class),
			$userSession,
			$this->createMock(LoggerInterface::class),
			new PathNormalizer(),
			$this->createMock(PadFileService::class),
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(PadBootstrapService::class),
			$this->createMock(AppConfigService::class),
			$this->createMock(LifecycleService::class),
			$this->createMock(IRootFolder::class),
		);
	}
}
