<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileOperationService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadInitializationServiceTest extends TestCase {
	public function testInitializeReturnsExistingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')
			->with('content')
			->willReturn([
				'frontmatter' => [
					'pad_id' => 'g.ABC$pad',
					'access_mode' => BindingService::ACCESS_PUBLIC,
				],
			]);

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->never())->method('initializeMissingFrontmatter');

		$result = (new PadInitializationService($padFileService, $fileOperations, $bootstrap))
			->initialize('alice', $file, 'content');

		$this->assertSame([
			'status' => 'already_initialized',
			'file' => '/Existing.pad',
			'file_id' => 42,
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
		], $result);
	}

	public function testInitializeBootstrapsMissingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturn('updated-content');

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Legacy.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$parseCalls = 0;
		$padFileService->expects($this->exactly(2))
			->method('parsePadFile')
			->willReturnCallback(static function (string $content) use (&$parseCalls): array {
				$parseCalls++;
				if ($parseCalls === 1) {
					throw new MissingFrontmatterException('Missing frontmatter.');
				}
				TestCase::assertSame('updated-content', $content);
				return [
					'frontmatter' => [
						'pad_id' => 'g.XYZ$pad',
						'access_mode' => BindingService::ACCESS_PROTECTED,
					],
				];
			});

		$bootstrap = $this->createMock(PadBootstrapService::class);
		$bootstrap->expects($this->once())
			->method('initializeMissingFrontmatter')
			->with($file, 'legacy-content');

		$result = (new PadInitializationService($padFileService, $fileOperations, $bootstrap))
			->initialize('alice', $file, 'legacy-content');

		$this->assertSame([
			'status' => 'initialized',
			'file' => '/Legacy.pad',
			'file_id' => 42,
			'pad_id' => 'g.XYZ$pad',
			'access_mode' => BindingService::ACCESS_PROTECTED,
		], $result);
	}
}
