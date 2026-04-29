<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadPathService;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use PHPUnit\Framework\TestCase;

class PadPathServiceTest extends TestCase {
	public function testNormalizeCreatePathRejectsEmptyPath(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildService()->normalizeCreatePath('   ');
	}

	public function testNormalizeCreatePathAppendsPadExtension(): void {
		$this->assertSame('/Notes.pad', $this->buildService()->normalizeCreatePath('/Notes'));
	}

	private function buildService(): PadPathService {
		return new PadPathService(new PathNormalizer());
	}
}
