<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class EtherpadClientTest extends TestCase {
	public function testBuildPadUrlUsesConfiguredHost(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return 'https://pad.example.test';
				}
				return $default;
			}
		);

		$client = new EtherpadClient($config);
		$this->assertSame(
			'https://pad.example.test/p/g.group%24pad-name',
			$client->buildPadUrl('g.group$pad-name')
		);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig());

		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$client = new EtherpadClient($this->buildExternalEnabledConfig());

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('Only public pad URLs can be linked from external servers.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/g.group$protected-pad');
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

		$client = new EtherpadClient($config);

		$this->expectException(EtherpadClientException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$client->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
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
