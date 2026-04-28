<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadPathService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadOpenServiceTest extends TestCase {
	public function testOpenByPathRejectsEmptyNormalizedPath(): void {
		$padPaths = $this->createMock(PadPathService::class);
		$padPaths->method('normalizeViewerFilePath')
			->with(" \t")
			->willReturn('');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->never())->method('resolveUserFileNodeByPath');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildService($padPaths, $userNodeResolver)
			->openByPath('alice', 'Alice', " \t");
	}

	private function buildService(PadPathService $padPaths, UserNodeResolver $userNodeResolver): PadOpenService {
		return new PadOpenService(
			$this->createMock(PadFileService::class),
			$padPaths,
			$userNodeResolver,
			$this->createMock(PadFileLockRetryService::class),
			$this->createMock(BindingService::class),
			$this->createMock(EtherpadClient::class),
			$this->createMock(PadSessionService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
