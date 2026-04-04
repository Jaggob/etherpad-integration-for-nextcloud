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
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
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

	public function testCreateByParentReturnsUnauthorizedWhenNoUserSession(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(123, 'Test');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required.', $response->getData()['message']);
	}

	public function testCreateByParentRejectsInvalidParentFolderId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(0, 'Test');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid parentFolderId.', $response->getData()['message']);
	}

	public function testCreateByParentRejectsInvalidAccessMode(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->createByParent(12, 'Test', 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid accessMode. Use public or protected.', $response->getData()['message']);
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

	public function testOpenByIdRetriesLockedReadAndEventuallySucceeds(): void {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(3))
			->method('getContent')
			->willReturnCallback(static function (): string {
				static $call = 0;
				$call++;
				if ($call < 3) {
					throw new LockedException('locked');
				}
				return 'frontmatter';
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parsePadFile')
			->with('frontmatter')
			->willReturn([
				'frontmatter' => [
					'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
			]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABCDEFGHIJKLMNOP$pad-1', BindingService::ACCESS_PUBLIC);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('buildPadUrl')
			->with('g.ABCDEFGHIJKLMNOP$pad-1')
			->willReturn('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1');

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->expects($this->once())
			->method('getSyncIntervalSeconds')
			->willReturn(30);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnMap([
				['etherpad_nextcloud.pad.syncById', ['fileId' => 138], '/sync/138'],
				['etherpad_nextcloud.pad.syncStatusById', ['fileId' => 138], '/sync-status/138'],
			]);

		$controller = new PadController(
			'etherpad_nextcloud',
			$this->createMock(IRequest::class),
			$urlGenerator,
			$userSession,
			$this->createMock(LoggerInterface::class),
			new PathNormalizer(),
			$padFileService,
			$bindingService,
			$etherpadClient,
			$this->createMock(PadSessionService::class),
			$this->createMock(PadBootstrapService::class),
			$appConfigService,
			$this->createMock(LifecycleService::class),
			$rootFolder,
			new UserNodeResolver($rootFolder),
		);

		$response = $controller->openById(138);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://pad.example.test/p/g.ABCDEFGHIJKLMNOP$pad-1', $response->getData()['url']);
	}

	public function testOpenByIdReturnsRetryableErrorWhenReadRemainsLocked(): void {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(4))
			->method('getContent')
			->willThrowException(new LockedException('still locked'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$controller = $this->buildController(
			$this->createMock(IRequest::class),
			$userSession,
			rootFolder: $rootFolder,
		);
		$response = $controller->openById(138);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('Pad file is temporarily locked. Please retry.', $response->getData()['message']);
		$this->assertTrue($response->getData()['retryable']);
	}

	public function testMetaByIdRejectsInvalidFileId(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = $this->buildController($this->createMock(IRequest::class), $userSession);
		$response = $controller->metaById(0);

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

	public function testSyncByIdRetriesLockedWriteAndEventuallySucceeds(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '0']);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(3))
			->method('putContent')
			->with('updated-content')
			->willReturnCallback(static function (): void {
				static $call = 0;
				$call++;
				if ($call < 3) {
					throw new LockedException('locked');
				}
			});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotRevision')->willReturn(4);
		$padFileService->method('withExportSnapshot')->with("frontmatter", 'hello', '<p>hello</p>', 5)->willReturn('updated-content');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABCDEFGHIJKLMNOP$pad-1', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn(5);
		$etherpadClient->method('getText')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('hello');
		$etherpadClient->method('getHTML')->with('g.ABCDEFGHIJKLMNOP$pad-1')->willReturn('<p>hello</p>');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame('updated', $response->getData()['status']);
		$this->assertSame(2, $response->getData()['lock_retries']);
		$this->assertSame(5, $response->getData()['snapshot_rev']);
	}

	public function testSyncByIdReturnsLockedWhenWriteRemainsLocked(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->exactly(4))
			->method('putContent')
			->with('updated-content')
			->willThrowException(new LockedException('still locked'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotRevision')->willReturn(1);
		$padFileService->method('withExportSnapshot')->with("frontmatter", 'hello', '<p>hello</p>', 5)->willReturn('updated-content');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('getRevisionsCount')->willReturn(5);
		$etherpadClient->method('getText')->willReturn('hello');
		$etherpadClient->method('getHTML')->willReturn('<p>hello</p>');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame('locked', $response->getData()['status']);
		$this->assertTrue($response->getData()['retryable']);
		$this->assertSame(3, $response->getData()['lock_retries']);
	}

	public function testSyncByIdForcedProtectedSyncDoesNotRewriteWhenRevisionIsUnchanged(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->never())->method('putContent');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABCDEFGHIJKLMNOP$pad-1',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotRevision')->willReturn(5);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('getRevisionsCount')
			->with('g.ABCDEFGHIJKLMNOP$pad-1')
			->willReturn(5);
		$etherpadClient->expects($this->never())->method('getText');
		$etherpadClient->expects($this->never())->method('getHTML');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame('unchanged', $response->getData()['status']);
		$this->assertSame(5, $response->getData()['snapshot_rev']);
		$this->assertSame(5, $response->getData()['current_rev']);
	}

	public function testSyncByIdForcedExternalSyncDoesNotRewriteWhenTextIsUnchanged(): void {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '1']);
		$file = $this->buildPadFileNode();
		$file->expects($this->never())->method('putContent');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(138)->willReturn([$file]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->willReturn([
			'frontmatter' => [
				'pad_id' => 'ext.remote-pad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => 'https://pad.example.test/p/public-pad',
				'remote_pad_id' => 'public-pad',
				'pad_origin' => 'https://pad.example.test',
			],
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(true);
		$padFileService->method('getTextSnapshotForRestore')->willReturn('same text');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('assertConsistentMapping');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.example.test/p/public-pad')
			->willReturn(['pad_url' => 'https://pad.example.test/p/public-pad']);
		$etherpadClient->expects($this->once())
			->method('getPublicTextFromPadUrl')
			->with('https://pad.example.test/p/public-pad')
			->willReturn('same text');

		$controller = $this->buildController(
			$request,
			$userSession,
			rootFolder: $rootFolder,
			padFileService: $padFileService,
			bindingService: $bindingService,
			etherpadClient: $etherpadClient,
		);
		$response = $controller->syncById(138);

		$this->assertSame('unchanged', $response->getData()['status']);
		$this->assertTrue($response->getData()['external']);
	}

	private function buildController(
		IRequest $request,
		IUserSession $userSession,
		?IRootFolder $rootFolder = null,
		?PadFileService $padFileService = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
	): PadController {
		$resolvedRootFolder = $rootFolder ?? $this->createMock(IRootFolder::class);
		return new PadController(
			'etherpad_nextcloud',
			$request,
			$this->createMock(IURLGenerator::class),
			$userSession,
			$this->createMock(LoggerInterface::class),
			new PathNormalizer(),
			$padFileService ?? $this->createMock(PadFileService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(PadBootstrapService::class),
			$this->createMock(AppConfigService::class),
			$this->createMock(LifecycleService::class),
			$resolvedRootFolder,
			new UserNodeResolver($resolvedRootFolder),
		);
	}

	private function buildPadFileNode(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);
		$file->method('getName')->willReturn('Test.pad');
		$file->method('getPath')->willReturn('/alice/files/Test.pad');
		$file->method('getContent')->willReturn('frontmatter');
		return $file;
	}
}
