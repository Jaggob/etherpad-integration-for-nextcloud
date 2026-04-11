<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AppConfigServiceTest extends TestCase {
	public function testNormalizeTrustedEmbedOriginsRejectsInvalidTcpPortZero(): void {
		$service = new AppConfigService(
			$this->createMock(IConfig::class),
			$this->buildL10n(),
		);

		$this->expectException(AdminValidationException::class);
		$this->expectExceptionMessage('Trusted embed origins must use a valid TCP port');

		$service->normalizeTrustedEmbedOrigins('https://portal.example.test:0');
	}

	public function testNormalizeTrustedEmbedOriginsAcceptsUpperTcpPortBoundary(): void {
		$service = new AppConfigService(
			$this->createMock(IConfig::class),
			$this->buildL10n(),
		);

		$this->assertSame(
			'https://portal.example.test:65535',
			$service->normalizeTrustedEmbedOrigins('https://portal.example.test:65535')
		);
	}

	public function testNormalizeTrustedEmbedOriginsPreservesIpv6Brackets(): void {
		$service = new AppConfigService(
			$this->createMock(IConfig::class),
			$this->buildL10n(),
		);

		$this->assertSame(
			'https://[::1]:8443',
			$service->normalizeTrustedEmbedOrigins('https://[::1]:8443')
		);
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
