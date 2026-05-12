<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\ConsistencyCheckService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsistencyCheckServiceTest extends TestCase {
	public function testResolvePadFileNodeReturnsPadFileWhenPresent(): void {
		$padFile = $this->createMock(File::class);
		$padFile->method('getName')->willReturn('Document.pad');

		$otherFile = $this->createMock(File::class);
		$otherFile->method('getName')->willReturn('Document.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getById')
			->with(42)
			->willReturn([$otherFile, $padFile]);

		$service = new ConsistencyCheckService(
			$this->createMock(IDBConnection::class),
			$rootFolder,
			$this->createMock(PadFileService::class),
			$this->createMock(LoggerInterface::class),
		);

		$invoke = \Closure::bind(
			static fn (ConsistencyCheckService $instance, int $fileId): ?File => $instance->resolvePadFileNode($fileId),
			null,
			ConsistencyCheckService::class
		);
		$result = $invoke($service, 42);

		$this->assertSame($padFile, $result);
	}

	public function testResolvePadFileNodeReturnsNullWhenLookupFails(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getById')
			->with(99)
			->willThrowException(new \RuntimeException('storage unavailable'));

		$service = new ConsistencyCheckService(
			$this->createMock(IDBConnection::class),
			$rootFolder,
			$this->createMock(PadFileService::class),
			$this->createMock(LoggerInterface::class),
		);

		$invoke = \Closure::bind(
			static fn (ConsistencyCheckService $instance, int $fileId): ?File => $instance->resolvePadFileNode($fileId),
			null,
			ConsistencyCheckService::class
		);
		$result = $invoke($service, 99);

		$this->assertNull($result);
	}

	public function testExternalPadFilesAreExcludedFromMissingBindingChecks(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Remote.pad');
		$file->method('getContent')->willReturn('frontmatter');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getById')
			->with(77)
			->willReturn([$file]);

		$frontmatter = [
			'pad_id' => 'ext.remote',
			'access_mode' => 'public',
			'pad_origin' => 'https://pad.example.test',
			'remote_pad_id' => 'remote',
		];
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('parsePadFile')
			->with('frontmatter')
			->willReturn(['frontmatter' => $frontmatter]);
		$padFileService->expects($this->once())
			->method('extractPadMetadata')
			->with($frontmatter)
			->willReturn(['pad_id' => 'ext.remote']);

		$service = new ConsistencyCheckService(
			$this->createMock(IDBConnection::class),
			$rootFolder,
			$padFileService,
			$this->createMock(LoggerInterface::class),
		);

		$invoke = \Closure::bind(
			static fn (ConsistencyCheckService $instance, int $fileId): bool => $instance->isExternalPadFile($fileId),
			null,
			ConsistencyCheckService::class
		);

		$this->assertTrue($invoke($service, 77));
	}
}
