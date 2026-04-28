<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadSyncServiceTest extends TestCase {
	public function testSyncStatusReturnsUnavailableForExternalPads(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'ext.remote',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'ext.remote',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.example.test/p/remote',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(true);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'ext.remote', BindingService::ACCESS_PUBLIC);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService)
			->syncStatusById('alice', 138);

		$this->assertSame([
			'status' => 'unavailable',
			'in_sync' => null,
			'reason' => 'external_no_revision',
		], $result);
	}

	public function testSyncStatusReportsOutOfSyncInternalPad(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('frontmatter');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('resolveUserFileNodeById')->with('alice', 138)->willReturn($file);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PROTECTED,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->willReturn(false);
		$padFileService->method('getSnapshotRevision')->with('frontmatter')->willReturn(3);

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('assertConsistentMapping')
			->with(138, 'g.ABC$pad', BindingService::ACCESS_PROTECTED);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('getRevisionsCount')
			->with('g.ABC$pad')
			->willReturn(5);

		$result = $this->buildService($padFileService, $userNodeResolver, $bindingService, $etherpadClient)
			->syncStatusById('alice', 138);

		$this->assertSame([
			'status' => 'out_of_sync',
			'in_sync' => false,
			'snapshot_rev' => 3,
			'current_rev' => 5,
		], $result);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?UserNodeResolver $userNodeResolver = null,
		?BindingService $bindingService = null,
		?EtherpadClient $etherpadClient = null,
	): PadSyncService {
		return new PadSyncService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$userNodeResolver ?? $this->createMock(UserNodeResolver::class),
			$this->createMock(PadFileLockRetryService::class),
			$bindingService ?? $this->createMock(BindingService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
