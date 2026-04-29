<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadPathService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class PadInitializationServiceTest extends TestCase {
	public function testInitializeByPathResolvesFileAndReadsContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->once())
			->method('getContent')
			->willReturn('content');

		$padPaths = $this->createMock(PadPathService::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('/Existing.pad')
			->willReturn('/Existing.pad');
		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Existing.pad')
			->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

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

		$result = (new PadInitializationService($padFileService, $padPaths, $userNodeResolver, $bootstrap))
			->initializeByPath('alice', '/Existing.pad');

		$this->assertSame('already_initialized', $result['status']);
		$this->assertSame('/Existing.pad', $result['file']);
		$this->assertSame(42, $result['file_id']);
	}

	public function testInitializeByIdResolvesFileAndReadsContent(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->once())
			->method('getContent')
			->willReturn('content');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 42)
			->willReturn($file);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

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

		$result = (new PadInitializationService($padFileService, $this->createMock(PadPathService::class), $userNodeResolver, $bootstrap))
			->initializeById('alice', 42);

		$this->assertSame('already_initialized', $result['status']);
		$this->assertSame('/Existing.pad', $result['file']);
		$this->assertSame(42, $result['file_id']);
	}

	public function testInitializeByPathRejectsEmptyPath(): void {
		$padPaths = $this->createMock(PadPathService::class);
		$padPaths->expects($this->once())
			->method('normalizeViewerFilePath')
			->with('   ')
			->willReturn('');

		$this->expectException(\InvalidArgumentException::class);

		(new PadInitializationService(
			$this->createMock(PadFileService::class),
			$padPaths,
			$this->createMock(UserNodeResolver::class),
			$this->createMock(PadBootstrapService::class),
		))->initializeByPath('alice', '   ');
	}

	public function testInitializeReturnsExistingFrontmatter(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Existing.pad');

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

		$result = (new PadInitializationService($padFileService, $this->createMock(PadPathService::class), $userNodeResolver, $bootstrap))
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

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Legacy.pad');

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

		$result = (new PadInitializationService($padFileService, $this->createMock(PadPathService::class), $userNodeResolver, $bootstrap))
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
