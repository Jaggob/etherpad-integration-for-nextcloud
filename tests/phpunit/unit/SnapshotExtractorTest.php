<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\SnapshotExtractor;
use OCA\EtherpadNextcloud\Service\SnapshotHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class SnapshotExtractorTest extends TestCase {
	public function testExtractReturnsTextAndSanitizedHtmlSnapshot(): void {
		$padFiles = $this->createMock(PadFileService::class);
		$padFiles->expects($this->once())
			->method('getTextSnapshotForRestore')
			->with('pad-content')
			->willReturn('Snapshot text');
		$padFiles->expects($this->once())
			->method('getHtmlSnapshotForRestore')
			->with('pad-content')
			->willReturn('<h1 onclick="x">Title</h1><script>x()</script>');

		$result = (new SnapshotExtractor($padFiles, new SnapshotHtmlSanitizer()))->extract('pad-content');

		$this->assertSame('Snapshot text', $result->text);
		$this->assertSame('<h1>Title</h1>', $result->html);
	}
}
