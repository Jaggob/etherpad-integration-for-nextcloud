<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

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

	public function testGetConfiguredOriginNormalizesScheme(): void {
		$client = new EtherpadClient($this->configWithHost('HTTPS://Pad.Example.Test/'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginOmitsDefaultPorts(): void {
		$client = new EtherpadClient($this->configWithHost('https://pad.example.test:443'));
		$this->assertSame('https://pad.example.test', $client->getConfiguredOrigin());

		$client = new EtherpadClient($this->configWithHost('http://pad.example.test:80'));
		$this->assertSame('http://pad.example.test', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginKeepsNonDefaultPort(): void {
		$client = new EtherpadClient($this->configWithHost('https://pad.example.test:9001'));
		$this->assertSame('https://pad.example.test:9001', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginAllowsHttp(): void {
		// Unlike `parsePublicPadUrl`, the configured-origin accessor must not
		// enforce https — admins may legitimately run Etherpad on http behind
		// a private network.
		$client = new EtherpadClient($this->configWithHost('http://pad.internal.lan'));
		$this->assertSame('http://pad.internal.lan', $client->getConfiguredOrigin());
	}

	public function testGetConfiguredOriginReturnsEmptyWhenUnconfigured(): void {
		$client = new EtherpadClient($this->configWithHost(''));
		$this->assertSame('', $client->getConfiguredOrigin());
	}

	private function configWithHost(string $host): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($host): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'etherpad_host') {
					return $host;
				}
				return $default;
			}
		);
		return $config;
	}
}
