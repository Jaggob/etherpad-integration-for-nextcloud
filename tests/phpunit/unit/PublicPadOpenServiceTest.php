<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class PublicPadOpenServiceTest extends TestCase {
	public function testProtectedReadOnlyReturnsSanitizedSnapshot(): void {
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->expects($this->once())->method('getTextSnapshotForRestore')->with('content')->willReturn('Snapshot text');
		$padFiles->expects($this->once())->method('getHtmlSnapshotForRestore')->with('content')->willReturn('<h1 onclick="x">Title</h1><script>x</script>');

		$result = $this->buildService($padFiles)->open(
			'g.group$pad',
			BindingService::ACCESS_PROTECTED,
			true,
			'token',
			false,
			'content',
		);

		$this->assertSame('', $result->url);
		$this->assertTrue($result->isReadOnlySnapshot);
		$this->assertSame('Snapshot text', $result->snapshotText);
		$this->assertSame('<h1>Title</h1>', $result->snapshotHtml);
		$this->assertSame('', $result->cookieHeader);
	}

	public function testProtectedWritableCreatesPublicShareSession(): void {
		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->once())
			->method('createProtectedOpenContext')
			->with('public-share:token', 'Public share', 'g.group$pad', 3600)
			->willReturn(['url' => 'https://pad.example/p/g.group$pad', 'cookie' => ['name' => 'sessionID']]);
		$sessions->expects($this->once())
			->method('buildSetCookieHeader')
			->with(['name' => 'sessionID'])
			->willReturn('sessionID=abc; Path=/');

		$result = $this->buildService(padSessionService: $sessions)->open(
			'g.group$pad',
			BindingService::ACCESS_PROTECTED,
			false,
			'token',
			false,
			'content',
		);

		$this->assertSame('https://pad.example/p/g.group$pad', $result->url);
		$this->assertSame('sessionID=abc; Path=/', $result->cookieHeader);
		$this->assertFalse($result->isReadOnlySnapshot);
	}

	public function testExternalPublicPadReturnsNormalizedUrlAndTextSnapshot(): void {
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->expects($this->once())->method('getTextSnapshotForRestore')->with('content')->willReturn('External snapshot');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('normalizeAndValidateExternalPublicPadUrl')
			->with('https://remote.example/p/Test')
			->willReturn(['pad_url' => 'https://remote.example/p/Test']);

		$result = $this->buildService($padFiles, $etherpad)->open(
			'ext.abc',
			BindingService::ACCESS_PUBLIC,
			true,
			'token',
			true,
			'content',
			'https://remote.example/p/Test',
		);

		$this->assertSame('https://remote.example/p/Test', $result->url);
		$this->assertSame('https://remote.example/p/Test', $result->originalPadUrl);
		$this->assertSame('External snapshot', $result->snapshotText);
		$this->assertSame('', $result->snapshotHtml);
	}

	public function testExternalProtectedMetadataIsRejected(): void {
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad metadata requires public access_mode.');

		$this->buildService()->open(
			'ext.abc',
			BindingService::ACCESS_PROTECTED,
			false,
			'token',
			true,
			'content',
			'https://remote.example/p/Test',
		);
	}

	public function testExternalPadWithoutUrlIsRejected(): void {
		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad URL metadata is missing or invalid.');

		$this->buildService()->open(
			'ext.abc',
			BindingService::ACCESS_PUBLIC,
			false,
			'token',
			true,
			'content',
			'',
		);
	}

	public function testInternalReadOnlyUsesEtherpadReadOnlyUrl(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('getReadOnlyPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/r.public-pad');

		$result = $this->buildService(etherpadClient: $etherpad)->open(
			'public-pad',
			BindingService::ACCESS_PUBLIC,
			true,
			'token',
			false,
			'content',
		);

		$this->assertSame('https://pad.example/p/r.public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader);
	}

	public function testInternalWritableUsesPublicPadUrlWithoutSession(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('buildPadUrl')
			->with('public-pad')
			->willReturn('https://pad.example/p/public-pad');

		$sessions = $this->createMock(PadSessionService::class);
		$sessions->expects($this->never())->method('createProtectedOpenContext');

		$result = $this->buildService(etherpadClient: $etherpad, padSessionService: $sessions)->open(
			'public-pad',
			BindingService::ACCESS_PUBLIC,
			false,
			'token',
			false,
			'content',
		);

		$this->assertSame('https://pad.example/p/public-pad', $result->url);
		$this->assertSame('', $result->cookieHeader);
	}

	private function buildService(
		?PadFileService $padFileService = null,
		?EtherpadClient $etherpadClient = null,
		?PadSessionService $padSessionService = null,
	): PublicPadOpenService {
		return new PublicPadOpenService(
			$padFileService ?? $this->createMock(PadFileService::class),
			$etherpadClient ?? $this->createMock(EtherpadClient::class),
			$padSessionService ?? $this->createMock(PadSessionService::class),
			new SnapshotHtmlSanitizer(),
		);
	}
}
