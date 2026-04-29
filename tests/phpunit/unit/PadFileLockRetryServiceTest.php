<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileLockRetryExhaustedException;
use OCA\EtherpadNextcloud\Service\PadFileLockRetryService;
use OCP\Files\File;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

class PadFileLockRetryServiceTest extends TestCase {
	public function testReadContentPerformsFinalAttemptAfterConfiguredRetries(): void {
		$sleeps = [];
		$service = new PadFileLockRetryService(static function (int $delay) use (&$sleeps): void {
			$sleeps[] = $delay;
		});

		$calls = 0;
		$file = $this->createMock(File::class);
		$file->expects($this->exactly(4))
			->method('getContent')
			->willReturnCallback(static function () use (&$calls): string {
				$calls++;
				if ($calls <= 3) {
					throw new LockedException('locked');
				}
				return 'content';
			});

		$this->assertSame('content', $service->readContentWithOpenLockRetry($file));
		$this->assertSame([100000, 200000, 400000], $sleeps);
	}

	public function testPutContentPerformsFinalAttemptAfterConfiguredRetries(): void {
		$sleeps = [];
		$service = new PadFileLockRetryService(static function (int $delay) use (&$sleeps): void {
			$sleeps[] = $delay;
		});

		$calls = 0;
		$file = $this->createMock(File::class);
		$file->expects($this->exactly(4))
			->method('putContent')
			->with('content')
			->willReturnCallback(static function () use (&$calls): void {
				$calls++;
				if ($calls <= 3) {
					throw new LockedException('locked');
				}
			});

		$lockRetries = $service->putContentWithSyncLockRetry($file, 'content');

		$this->assertSame(3, $lockRetries);
		$this->assertSame([150000, 300000, 600000], $sleeps);
	}

	public function testPutContentThrowsRetryExhaustedWithRetryCount(): void {
		$sleeps = [];
		$service = new PadFileLockRetryService(static function (int $delay) use (&$sleeps): void {
			$sleeps[] = $delay;
		});

		$file = $this->createMock(File::class);
		$file->expects($this->exactly(4))
			->method('putContent')
			->with('content')
			->willThrowException(new LockedException('locked'));

		try {
			$service->putContentWithSyncLockRetry($file, 'content');
			$this->fail('Expected lock retry exhaustion.');
		} catch (PadFileLockRetryExhaustedException $e) {
			$this->assertSame(3, $e->getRetryAttempts());
			$this->assertSame('locked', $e->getLockedException()->getMessage());
			$this->assertSame([150000, 300000, 600000], $sleeps);
		}
	}
}
