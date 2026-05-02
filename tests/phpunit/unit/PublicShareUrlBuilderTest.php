<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PublicShareUrlBuilderTest extends TestCase {
	public function testBuildShareBaseUrlEncodesToken(): void {
		$this->assertSame('/nc/s/token%20with%2Fslash', $this->buildBuilder('/nc/')->buildShareBaseUrl('token with/slash'));
	}

	public function testBuildShareRedirectUrlDefaultsToRootDirectory(): void {
		$this->assertSame('/s/share-token?dir=%2F', $this->buildBuilder()->buildShareRedirectUrl('share-token', ''));
	}

	public function testBuildShareRedirectUrlBuildsNestedPadSelection(): void {
		$this->assertSame(
			'/s/share-token?path=%2FFolder%2FSub&files=Shared.pad',
			$this->buildBuilder()->buildShareRedirectUrl('share-token', '/Folder/Sub/Shared.pad')
		);
	}

	public function testBuildShareRedirectUrlRejectsNonPadFile(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The selected file is not a .pad document.');

		$this->buildBuilder()->buildShareRedirectUrl('share-token', '/Folder/Text.txt');
	}

	public function testBuildShareRedirectUrlRejectsInvalidPath(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildBuilder()->buildShareRedirectUrl('share-token', '../Shared.pad');
	}

	public function testBuildDownloadUrlForSingleFileShare(): void {
		$this->assertSame('/s/share-token/download', $this->buildBuilder()->buildDownloadUrl('share-token', '', false, 'Shared.pad'));
	}

	public function testBuildDownloadUrlForFolderShare(): void {
		$this->assertSame(
			'/s/share-token/download?path=%2FFolder&files=Shared.pad',
			$this->buildBuilder()->buildDownloadUrl('share-token', '/Folder/Shared.pad', true, 'Shared.pad')
		);
	}

	public function testBuildDownloadUrlReturnsEmptyForFolderShareWithoutSelection(): void {
		$this->assertSame('', $this->buildBuilder()->buildDownloadUrl('share-token', '', true, 'Shared.pad'));
	}

	private function buildBuilder(string $webroot = ''): PublicShareUrlBuilder {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn($webroot);
		return new PublicShareUrlBuilder($urlGenerator, new PathNormalizer());
	}
}
