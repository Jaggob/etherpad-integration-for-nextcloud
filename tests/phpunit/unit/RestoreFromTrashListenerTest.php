<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RestoreFromTrashListenerTest extends TestCase {
	public function testTypedRestoreEventRestoresTargetFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleService::RESULT_RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$this->createMock(IUserSession::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$listener->handle(new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getTarget(): File {
				return $this->file;
			}
		});
	}

	public function testLegacyRestoreHookResolvesFilePathInUserFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('get')
			->with('G - Jacobs Test Gruppe/Neues Pad 9.pad')
			->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleRestore')
			->with($file)
			->willReturn(['status' => LifecycleService::RESULT_RESTORED]);

		$listener = new RestoreFromTrashListener(
			$lifecycleService,
			$userSession,
			$rootFolder,
			$this->createMock(LoggerInterface::class),
		);

		$listener->handleLegacyHook([
			'filePath' => '/G - Jacobs Test Gruppe/Neues Pad 9.pad',
			'trashPath' => 'files_trashbin/files/Neues Pad 9.pad.d1777397341',
		]);
	}
}
