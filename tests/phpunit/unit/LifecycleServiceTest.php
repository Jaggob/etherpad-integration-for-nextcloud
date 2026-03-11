<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LifecycleServiceTest extends TestCase {
	public function testHandleTrashSkipsNonPadFiles(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->never())->method('findByFileId');

		$padFileService = $this->createMock(PadFileService::class);
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(12);
		$file->method('getName')->willReturn('Notes.txt');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('not_pad_file', $result['reason']);
		$this->assertSame(12, $result['file_id']);
	}

	public function testHandleTrashMarksPendingDeleteWhenEtherpadDeleteFails(): void {
		$fileId = 21;
		$padId = 'pad-abc';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())
			->method('markTrashed')
			->with(
				$fileId,
				$this->callback(static fn ($deletedAt): bool => is_int($deletedAt) && $deletedAt > 0)
			);
		$bindingService->expects($this->once())
			->method('markPendingDelete')
			->with(
				$fileId,
				$this->callback(static fn ($deletedAt): bool => is_int($deletedAt) && $deletedAt > 0)
			);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('doc-current', 'snapshot-text', '<p>snapshot-html</p>', 7)
			->willReturn('doc-with-export');
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with(
				'doc-with-export',
				BindingService::STATE_TRASHED,
				'snapshot-text',
				null,
				$this->callback(static fn ($deletedAt): bool => is_int($deletedAt) && $deletedAt > 0)
			)
			->willReturn('doc-trash-updated');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('getText')->with($padId)->willReturn('snapshot-text');
		$etherpadClient->expects($this->once())->method('getHTML')->with($padId)->willReturn('<p>snapshot-html</p>');
		$etherpadClient->expects($this->once())->method('getRevisionsCount')->with($padId)->willReturn(7);
		$etherpadClient->expects($this->once())
			->method('deletePad')
			->with($padId)
			->willThrowException(new \RuntimeException('temporary failure'));

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-current');
		$file->expects($this->once())->method('putContent')->with('doc-trash-updated');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_TRASHED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
		$this->assertTrue($result['snapshot_persisted']);
		$this->assertTrue($result['delete_pending']);
	}

	public function testHandleTrashReturnsSkippedOnStateTransitionConflict(): void {
		$fileId = 55;
		$padId = 'pad-race';

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_ACTIVE,
			]);
		$bindingService->expects($this->once())
			->method('markTrashed')
			->willThrowException(new BindingStateConflictException('race'));

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->never())->method('withExportSnapshot');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->never())->method('deletePad');

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Pad.pad');
		$file->expects($this->once())->method('getContent')->willReturn('');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleTrash($file);
		$this->assertSame(LifecycleService::RESULT_SKIPPED, $result['status']);
		$this->assertSame('binding_state_transition_conflict', $result['reason']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($padId, $result['pad_id']);
	}

	public function testHandleRestoreFallsBackToTextWhenHtmlRestoreFails(): void {
		$fileId = 83;
		$oldPadId = 'old-pad';
		$newPadId = 'r-old-pad-abc123def456';
		$newPadUrl = 'https://pad.example.test/p/' . rawurlencode($newPadId);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByFileId')
			->with($fileId)
			->willReturn([
				'file_id' => $fileId,
				'pad_id' => $oldPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'state' => BindingService::STATE_TRASHED,
			]);
		$bindingService->expects($this->once())
			->method('markRestored')
			->with($fileId, $newPadId);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())->method('getTextSnapshotForRestore')->with('doc-before')->willReturn('plain text');
		$padFileService->expects($this->once())->method('getHtmlSnapshotForRestore')->with('doc-before')->willReturn('<p>html text</p>');
		$padFileService->expects($this->once())
			->method('withStateAndSnapshot')
			->with(
				'doc-before',
				BindingService::STATE_ACTIVE,
				'plain text',
				$newPadId,
				null,
				$newPadUrl
			)
			->willReturn('doc-after');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())->method('createPad')->with($newPadId);
		$etherpadClient->expects($this->once())
			->method('setHTML')
			->with($newPadId, '<p>html text</p>')
			->willThrowException(new \RuntimeException('setHTML unsupported'));
		$etherpadClient->expects($this->once())->method('setText')->with($newPadId, 'plain text');
		$etherpadClient->expects($this->once())->method('buildPadUrl')->with($newPadId)->willReturn($newPadUrl);
		$etherpadClient->expects($this->never())->method('deletePad');

		$config = $this->buildDeleteOnTrashEnabledConfig();
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->expects($this->once())
			->method('generate')
			->with(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abc123def456');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Restored.pad');
		$file->expects($this->once())->method('getContent')->willReturn('doc-before');
		$file->expects($this->once())->method('putContent')->with('doc-after');

		$service = new LifecycleService(
			$bindingService,
			$padFileService,
			$etherpadClient,
			$config,
			$logger,
			$secureRandom
		);

		$result = $service->handleRestore($file);
		$this->assertSame(LifecycleService::RESULT_RESTORED, $result['status']);
		$this->assertSame($fileId, $result['file_id']);
		$this->assertSame($oldPadId, $result['old_pad_id']);
		$this->assertSame($newPadId, $result['new_pad_id']);
	}

	private function buildDeleteOnTrashEnabledConfig(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'delete_on_trash') {
					return 'yes';
				}
				if ($appName === 'etherpad_nextcloud' && $key === 'test_fault') {
					return '';
				}
				return $default;
			}
		);
		$config->method('getSystemValueBool')->willReturn(false);
		return $config;
	}
}
