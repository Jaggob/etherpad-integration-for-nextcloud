<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileOperationService;
use OCA\EtherpadNextcloud\Service\PadLifecycleOperationService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadLifecycleOperationServiceTest extends TestCase {
	public function testTrashByPathFormatsSkippedLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('/Test.pad')
			->willReturn('/Test.pad');
		$fileOperations->expects($this->once())
			->method('resolveUserPadNode')
			->with('alice', '/Test.pad')
			->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->expects($this->once())
			->method('handleTrash')
			->with($file)
			->willReturn([
				'status' => LifecycleService::RESULT_SKIPPED,
				'reason' => 'delete_on_trash_disabled',
				'file_id' => 42,
			]);

		$result = (new PadLifecycleOperationService($fileOperations, $lifecycleService))
			->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_SKIPPED,
			'reason' => 'delete_on_trash_disabled',
		], $result);
	}

	public function testTrashByPathFormatsTrashedLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('normalizeViewerFilePath')->with('/Test.pad')->willReturn('/Test.pad');
		$fileOperations->method('resolveUserPadNode')->with('alice', '/Test.pad')->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleTrash')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_TRASHED,
			'deleted_at' => 1234,
			'snapshot_persisted' => true,
			'delete_pending' => false,
		]);

		$result = (new PadLifecycleOperationService($fileOperations, $lifecycleService))
			->trashByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_TRASHED,
			'deleted_at' => 1234,
			'snapshot_persisted' => true,
			'delete_pending' => false,
		], $result);
	}

	public function testRestoreByPathFormatsRestoredLifecycleResult(): void {
		$file = $this->createMock(File::class);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('normalizeViewerFilePath')->with('/Test.pad')->willReturn('/Test.pad');
		$fileOperations->method('resolveUserPadNode')->with('alice', '/Test.pad')->willReturn($file);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleRestore')->with($file)->willReturn([
			'status' => LifecycleService::RESULT_RESTORED,
			'old_pad_id' => 'old-pad',
			'new_pad_id' => 'new-pad',
		]);

		$result = (new PadLifecycleOperationService($fileOperations, $lifecycleService))
			->restoreByPath('alice', '/Test.pad');

		$this->assertSame([
			'file' => '/Test.pad',
			'status' => LifecycleService::RESULT_RESTORED,
			'old_pad_id' => 'old-pad',
			'new_pad_id' => 'new-pad',
		], $result);
	}

	public function testTrashByPathRejectsEmptyPath(): void {
		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('   ')
			->willReturn('');

		$this->expectException(\InvalidArgumentException::class);

		(new PadLifecycleOperationService(
			$fileOperations,
			$this->createMock(LifecycleService::class),
		))->trashByPath('alice', '   ');
	}
}
