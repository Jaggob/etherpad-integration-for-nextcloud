<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PadControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PadControllerErrorMapperTest extends TestCase {
	public function testRunMapsInvalidArgumentWithConfiguredMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \InvalidArgumentException('raw'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['invalid_argument' => 'Invalid file path.'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file path.', $response->getData()['message']);
	}

	public function testRunMapsBindingExceptionWithConfiguredConflictMessage(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new BindingException('duplicate'),
			static fn(array $result): DataResponse => new DataResponse($result),
			[
				'binding_message' => '.pad file already exists.',
				'binding_status' => Http::STATUS_CONFLICT,
			],
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('.pad file already exists.', $response->getData()['message']);
	}

	public function testRunMapsRuntimeCreateConflict(): void {
		$rollbackService = $this->createMock(PadCreateRollbackService::class);
		$rollbackService->method('isCreateConflict')->willReturn(true);

		$response = $this->buildMapper($rollbackService)->run(
			static fn(): array => throw new \RuntimeException('exists', Http::STATUS_CONFLICT),
			static fn(array $result): DataResponse => new DataResponse($result),
			['conflict_message' => '.pad file already exists.'],
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('.pad file already exists.', $response->getData()['message']);
	}

	public function testRunMapsRuntimeExceptionToGenericMessageByDefault(): void {
		$response = $this->buildMapper()->run(
			static fn(): array => throw new \RuntimeException('Detailed failure.'),
			static fn(array $result): DataResponse => new DataResponse($result),
			['generic' => 'Pad open failed.'],
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Pad open failed.', $response->getData()['message']);
	}

	private function buildMapper(?PadCreateRollbackService $rollbackService = null): PadControllerErrorMapper {
		return new PadControllerErrorMapper(
			$rollbackService ?? $this->createMock(PadCreateRollbackService::class),
			new PadResponseService(
				$this->createMock(IURLGenerator::class),
				$this->createMock(AppConfigService::class),
			),
		);
	}
}
