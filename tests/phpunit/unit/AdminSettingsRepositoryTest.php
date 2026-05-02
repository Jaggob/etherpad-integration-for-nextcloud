<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminSettingsRepository;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdminSettingsRepositoryTest extends TestCase {
	public function testPersistStoresValidatedSettings(): void {
		$saved = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $value) use (&$saved): void {
				if ($appName === 'etherpad_nextcloud') {
					$saved[$key] = $value;
				}
			}
		);

		(new AdminSettingsRepository($config))->persist(new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			'new-key',
			'new-key',
			'1.3.0',
			90,
			false,
			true,
			'https://external.example.test:8443',
			'https://portal.example.test',
		));

		$this->assertSame('https://pad.example.test', $saved['etherpad_host']);
		$this->assertSame('https://pad-api.example.test', $saved['etherpad_api_host']);
		$this->assertSame('.example.test', $saved['etherpad_cookie_domain']);
		$this->assertSame('yes', $saved['etherpad_cookie_domain_configured']);
		$this->assertSame('new-key', $saved['etherpad_api_key']);
		$this->assertSame('1.3.0', $saved['etherpad_api_version']);
		$this->assertSame('90', $saved['sync_interval_seconds']);
		$this->assertSame('no', $saved['delete_on_trash']);
		$this->assertSame('yes', $saved['allow_external_pads']);
		$this->assertSame('https://external.example.test:8443', $saved['external_pad_allowlist']);
		$this->assertSame('https://portal.example.test', $saved['trusted_embed_origins']);
	}

	public function testPersistDoesNotOverwriteApiKeyWhenNoNewKeyWasSubmitted(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $appName, string $key): void {
				TestCase::assertFalse($appName === 'etherpad_nextcloud' && $key === 'etherpad_api_key');
			}
		);

		(new AdminSettingsRepository($config))->persist(new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad.example.test',
			'',
			null,
			'stored-key',
			'1.3.0',
			120,
			true,
			false,
			'',
			'',
		));
	}
}
