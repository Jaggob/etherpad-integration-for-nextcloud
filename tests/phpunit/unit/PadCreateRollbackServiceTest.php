<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateRollbackServiceTest extends TestCase {
	public function testRollbackDoesNotDeleteExistingNodeWhenCreateDidNotCreateFile(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$this->buildService($rootFolder)
			->rollbackFailedCreate('alice', '/Existing.pad', '', false);
	}

	public function testRollbackDeletesCreatedFileWhenItStillExists(): void {
		$deletedNode = new class {
			public bool $deleted = false;

			public function delete(): void {
				$this->deleted = true;
			}
		};

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('nodeExists')
			->with('Created.pad')
			->willReturn(true);
		$userFolder->expects($this->once())
			->method('get')
			->with('Created.pad')
			->willReturn($deletedNode);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$this->buildService($rootFolder)
			->rollbackFailedCreate('alice', '/Created.pad', '', true);

		$this->assertTrue($deletedNode->deleted);
	}

	private function buildService(IRootFolder $rootFolder): PadCreateRollbackService {
		return new PadCreateRollbackService(
			$rootFolder,
			$this->createMock(EtherpadClient::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
