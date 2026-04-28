<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileOperationService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadMetadataServiceTest extends TestCase {
	public function testMetaByIdReturnsExternalPublicPadMetadata(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'External.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
		]);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('resolveUserPadNodeById')->with('alice', 138)->willReturn($file);
		$fileOperations->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/External.pad');
		$fileOperations->method('readContentWithOpenLockRetry')->with($file)->willReturn('frontmatter');

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
			'pad_url' => 'https://pad.example.test/p/External',
		]);
		$padFileService->method('isExternalFrontmatter')->with($this->isType('array'), 'ext.remote')->willReturn(true);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://pad.example.test/p/External')
			->willReturn(['pad_url' => 'https://pad.example.test/p/External']);

		$result = $this->buildService($padFileService, $fileOperations, $etherpadClient)
			->metaById('alice', 138);

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 138,
			'name' => 'External.pad',
			'path' => '/External.pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'is_external' => true,
			'pad_id' => 'ext.remote',
			'pad_url' => 'https://pad.example.test/p/External',
			'public_open_url' => 'https://pad.example.test/p/External',
		], $result);
	}

	public function testResolveReturnsFalseWhenFileIdIsMissing(): void {
		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('resolveUserPadNodeById')
			->with('alice', 404)
			->willThrowException(new NotFoundException('missing'));

		$result = $this->buildService(fileOperations: $fileOperations)
			->resolve('alice', 404);

		$this->assertSame(['is_pad' => false, 'file_id' => 404], $result);
	}

	public function testResolveReturnsPublicOpenUrlForInternalPublicPad(): void {
		$file = $this->createConfiguredMock(File::class, [
			'getId' => 138,
			'getName' => 'Public.pad',
			'getMimeType' => 'application/x-etherpad-nextcloud',
			'getContent' => 'frontmatter',
		]);

		$fileOperations = $this->createMock(PadFileOperationService::class);
		$fileOperations->method('resolveUserPadNodeById')->with('alice', 138)->willReturn($file);
		$fileOperations->method('toUserAbsolutePath')->with('alice', $file)->willReturn('/Public.pad');

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('parsePadFile')->with('frontmatter')->willReturn([
			'frontmatter' => [
				'pad_id' => 'g.ABC$pad',
				'access_mode' => BindingService::ACCESS_PUBLIC,
			],
		]);
		$padFileService->method('extractPadMetadata')->willReturn([
			'pad_id' => 'g.ABC$pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => '',
		]);
		$padFileService->method('isExternalFrontmatter')->with($this->isType('array'), 'g.ABC$pad')->willReturn(false);

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('g.ABC$pad')->willReturn('https://pad.example.test/p/g.ABC$pad');

		$result = $this->buildService($padFileService, $fileOperations, $etherpadClient)
			->resolve('alice', 138);

		$this->assertSame([
			'is_pad' => true,
			'is_pad_mime' => true,
			'file_id' => 138,
			'path' => '/Public.pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'is_external' => false,
			'public_open_url' => 'https://pad.example.test/p/g.ABC$pad',
		], $result);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?PadFileOperationService $fileOperations = null,
		?EtherpadClient $etherpadClient = null,
	): PadMetadataService {
		return new PadMetadataService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$fileOperations ?? $this->createMock(PadFileOperationService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
