<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\AllowlistNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AllowlistNormalizerTest extends TestCase {
	public function testNormalizesHostsAndHttpsOriginsWithPorts(): void {
		$this->assertSame(
			"pad.example.test\nhttps://etherpad.example.org:8443",
			$this->buildNormalizer()->normalize(" pad.example.test, https://etherpad.example.org:8443 ")
		);
	}

	public function testDeduplicatesEntries(): void {
		$this->assertSame('pad.example.test', $this->buildNormalizer()->normalize("pad.example.test\nPAD.EXAMPLE.TEST"));
	}

	public function testDropsDefaultHttpsPort(): void {
		$this->assertSame('https://pad.example.test', $this->buildNormalizer()->normalize('https://pad.example.test:443'));
	}

	public function testRejectsHttpOrigins(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('External allowlist URL must use https');

		$this->buildNormalizer()->normalize('http://pad.example.test');
	}

	public function testRejectsInvalidHosts(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('External allowlist contains invalid host');

		$this->buildNormalizer()->normalize('bad..host');
	}

	private function buildNormalizer(): AllowlistNormalizer {
		return new AllowlistNormalizer($this->buildL10n());
	}

	private function buildL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach ($parameters as $key => $value) {
					$text = str_replace('{' . $key . '}', (string)$value, $text);
				}
				return $text;
			}
		);
		return $l10n;
	}
}
