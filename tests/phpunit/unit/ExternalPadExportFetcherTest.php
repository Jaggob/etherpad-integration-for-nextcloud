<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class ExternalPadExportFetcherTest extends TestCase {
	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlKeepsLiteralPlusInPadId(): void {
		// `+` is literal in URL path segments. Using urldecode() previously
		// turned `team+pad` into pad-id `team pad`, then re-emitted
		// `/p/team%20pad` which hits a different / non-existent pad.
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team+meeting');
		$this->assertSame('team+meeting', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/team%2Bmeeting', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlDecodesPercentEncodedPlus(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team%2Bmeeting');
		$this->assertSame('team+meeting', $result['pad_id']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$fetcher = new ExternalPadExportFetcher($this->buildExternalEnabledConfig());

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Only public pad URLs can be linked from external servers.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/g.group$protected-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsWhenDisabledByAdmin(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'allow_external_pads') {
					return 'no';
				}
				return $default;
			}
		);

		$fetcher = new ExternalPadExportFetcher($config);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
	}

	private function buildExternalEnabledConfig(string $externalPadAllowlist = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($externalPadAllowlist): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				if ($key === 'allow_external_pads') {
					return 'yes';
				}
				if ($key === 'external_pad_allowlist') {
					return $externalPadAllowlist;
				}
				return $default;
			}
		);

		return $config;
	}
}
