<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCA\EtherpadNextcloud\Service\AdminTestFaultService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AdminTestFaultServiceTest extends TestCase {
	public function testSetFaultRequiresDebugMode(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(false);
		$config->expects($this->never())->method('setAppValue');

		$this->expectException(AdminDebugModeRequiredException::class);

		(new AdminTestFaultService($config))->setFault('trash_read_lock');
	}

	public function testSetFaultRejectsUnsupportedFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->never())->method('setAppValue');

		try {
			(new AdminTestFaultService($config))->setFault('unknown_fault');
			$this->fail('Expected unsupported test fault exception.');
		} catch (UnsupportedTestFaultException $e) {
			$this->assertContains('trash_read_lock', $e->getSupportedFaults());
		}
	}

	public function testSetFaultPersistsSupportedFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->once())
			->method('setAppValue')
			->with('etherpad_nextcloud', 'test_fault', 'trash_read_lock');

		$result = (new AdminTestFaultService($config))->setFault('trash_read_lock');

		$this->assertSame('trash_read_lock', $result);
	}

	public function testSetFaultAllowsClearingFault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn(true);
		$config->expects($this->once())
			->method('setAppValue')
			->with('etherpad_nextcloud', 'test_fault', '');

		$result = (new AdminTestFaultService($config))->setFault('');

		$this->assertSame('', $result);
	}
}
