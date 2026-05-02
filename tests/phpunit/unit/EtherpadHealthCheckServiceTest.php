<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\EtherpadHealthCheckService;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class EtherpadHealthCheckServiceTest extends TestCase {
	public function testCheckReturnsHealthCheckResult(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())
			->method('healthCheck')
			->with('https://pad-api.example.test', 'key', '1.3.0')
			->willReturn(['pad_count' => 42]);

		$pending = $this->createMock(PendingDeleteRetryService::class);
		$pending->expects($this->once())->method('countPendingDeletes')->willReturn(3);
		$pending->expects($this->once())->method('countTrashedWithoutFile')->willReturn(1);

		$result = (new EtherpadHealthCheckService($etherpad, $pending, $this->buildL10n()))->check($this->settings());

		$this->assertSame(42, $result->padCount);
		$this->assertSame(3, $result->pendingDeleteCount);
		$this->assertSame(1, $result->trashedWithoutFileCount);
		$this->assertSame('https://pad-api.example.test/api/1.3.0/listAllPads', $result->target);
	}

	public function testCheckDefaultsMissingPadCountToZero(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn([]);

		$result = (new EtherpadHealthCheckService(
			$etherpad,
			$this->pendingCounts(0, 0),
			$this->buildL10n(),
		))->check($this->settings());

		$this->assertSame(0, $result->padCount);
	}

	public function testCheckRoundsLatencyMilliseconds(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willReturn(['pad_count' => 1]);
		$ticks = [100.0000, 100.1246];

		$service = new class(
			$etherpad,
			$this->pendingCounts(0, 0),
			$this->buildL10n(),
			$ticks,
		) extends EtherpadHealthCheckService {
			/** @param list<float> $ticks */
			public function __construct(
				EtherpadClient $etherpadClient,
				PendingDeleteRetryService $pendingDeleteRetryService,
				IL10N $l10n,
				private array $ticks,
			) {
				parent::__construct($etherpadClient, $pendingDeleteRetryService, $l10n);
			}

			protected function now(): float {
				return array_shift($this->ticks) ?? 100.1246;
			}
		};

		$result = $service->check($this->settings());

		$this->assertSame(125, $result->latencyMs);
	}

	public function testCheckAddsAuthMethodHintForApiKeyMismatch(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('no or wrong API Key'));

		$this->expectException(AdminHealthCheckException::class);
		$this->expectExceptionMessage('authenticationMethod');

		(new EtherpadHealthCheckService($etherpad, $this->createMock(PendingDeleteRetryService::class), $this->buildL10n()))
			->check($this->settings());
	}

	public function testCheckDoesNotAddAuthHintForUnrelatedFailures(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('healthCheck')->willThrowException(new EtherpadClientException('connection refused'));

		try {
			(new EtherpadHealthCheckService($etherpad, $this->createMock(PendingDeleteRetryService::class), $this->buildL10n()))
				->check($this->settings());
			$this->fail('Expected health check exception.');
		} catch (AdminHealthCheckException $e) {
			$this->assertStringContainsString('connection refused', $e->getMessage());
			$this->assertStringNotContainsString('authenticationMethod', $e->getMessage());
		}
	}

	private function settings(): ValidatedAdminSettings {
		return new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			'key',
			'key',
			'1.3.0',
			120,
			true,
			true,
			'',
			'',
		);
	}

	private function pendingCounts(int $pendingDeleteCount, int $trashedWithoutFileCount): PendingDeleteRetryService {
		$pending = $this->createMock(PendingDeleteRetryService::class);
		$pending->method('countPendingDeletes')->willReturn($pendingDeleteCount);
		$pending->method('countTrashedWithoutFile')->willReturn($trashedWithoutFileCount);
		return $pending;
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
