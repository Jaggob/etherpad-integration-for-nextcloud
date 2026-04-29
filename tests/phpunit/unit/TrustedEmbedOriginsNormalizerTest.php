<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\TrustedEmbedOriginsNormalizer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class TrustedEmbedOriginsNormalizerTest extends TestCase {
	public function testNormalizeRejectsInvalidTcpPortZero(): void {
		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Trusted embed origins must use a valid TCP port');

		$this->buildNormalizer()->normalize('https://portal.example.test:0');
	}

	public function testNormalizeAcceptsUpperTcpPortBoundary(): void {
		$this->assertSame(
			'https://portal.example.test:65535',
			$this->buildNormalizer()->normalize('https://portal.example.test:65535')
		);
	}

	public function testNormalizePreservesIpv6Brackets(): void {
		$this->assertSame(
			'https://[::1]:8443',
			$this->buildNormalizer()->normalize('https://[::1]:8443')
		);
	}

	public function testParseSkipsInvalidEntriesWhenNotStrict(): void {
		$this->assertSame(
			['https://portal.example.test'],
			$this->buildNormalizer()->parse('http://bad.example.test, https://portal.example.test')
		);
	}

	private function buildNormalizer(): TrustedEmbedOriginsNormalizer {
		return new TrustedEmbedOriginsNormalizer($this->buildL10n());
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
