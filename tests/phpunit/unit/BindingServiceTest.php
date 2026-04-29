<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BindingServiceTest extends TestCase {
	public function testAssertConsistentMappingAcceptsActiveConsistentBinding(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 10,
			'pad_id' => 'pad-123',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$service->assertConsistentMapping(10, 'pad-123', BindingService::ACCESS_PUBLIC);
		$this->addToAssertionCount(1);
	}

	public function testAssertConsistentMappingRejectsPadIdMismatch(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 11,
			'pad_id' => 'pad-a',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$this->expectException(BindingException::class);
		$this->expectExceptionMessage('Binding pad ID mismatch.');
		$service->assertConsistentMapping(11, 'pad-b', BindingService::ACCESS_PROTECTED);
	}

	public function testAssertConsistentMappingRejectsMissingBindingWithSpecificException(): void {
		$service = $this->buildServiceWithBinding(null);

		$this->expectException(MissingBindingException::class);
		$this->expectExceptionMessage('No binding exists for this file.');

		$service->assertConsistentMapping(10, 'pad-123', BindingService::ACCESS_PUBLIC);
	}

	public function testAssertConsistentMappingRejectsUnsupportedAccessMode(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 12,
			'pad_id' => 'pad-a',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$this->expectException(BindingException::class);
		$this->expectExceptionMessage('Unsupported access mode: legacy');
		$service->assertConsistentMapping(12, 'pad-a', 'legacy');
	}

	/** @param array<string,mixed>|null $binding */
	private function buildServiceWithBinding(?array $binding): BindingService {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new class ($db, $logger, $binding) extends BindingService {
			/** @param array<string,mixed>|null $binding */
			public function __construct(IDBConnection $db, LoggerInterface $logger, private ?array $binding) {
				parent::__construct($db, $logger);
			}

			public function findByFileId(int $fileId): ?array {
				return $this->binding;
			}
		};
	}
}
