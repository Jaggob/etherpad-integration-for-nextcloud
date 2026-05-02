<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\AdminConsistencyCheckResponseBuilder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AdminConsistencyCheckResponseBuilderTest extends TestCase {
	public function testBuildsSuccessfulResponse(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult());

		$this->assertTrue((bool)$response['ok']);
		$this->assertSame('Consistency check successful. No issues found.', $response['message']);
		$this->assertSame(0, $response['binding_without_file_count']);
		$this->assertSame(0, $response['file_without_binding_count']);
		$this->assertSame(0, $response['invalid_frontmatter_count']);
		$this->assertFalse((bool)$response['frontmatter_scan_limit_reached']);
		$this->assertFalse((bool)$response['frontmatter_time_budget_exceeded']);
	}

	public function testBuildsIssueAndPartialMessage(): void {
		$response = $this->buildBuilder()->build($this->consistencyResult([
			'binding_without_file_count' => 2,
			'frontmatter_time_budget_exceeded' => true,
		]));

		$this->assertSame(
			'Consistency check finished with issues. Frontmatter validation result is partial (scan limit/time budget reached).',
			$response['message']
		);
		$this->assertSame(2, $response['binding_without_file_count']);
		$this->assertTrue((bool)$response['frontmatter_time_budget_exceeded']);
	}

	/** @param array<string,mixed> $overrides */
	private function consistencyResult(array $overrides = []): array {
		return $overrides + [
			'binding_without_file_count' => 0,
			'file_without_binding_count' => 0,
			'invalid_frontmatter_count' => 0,
			'frontmatter_scanned' => 10,
			'frontmatter_skipped' => 1,
			'frontmatter_scan_limit_reached' => false,
			'frontmatter_time_budget_exceeded' => false,
			'frontmatter_time_budget_ms' => 3000,
			'frontmatter_chunk_size' => 200,
			'samples' => [],
		];
	}

	private function buildBuilder(): AdminConsistencyCheckResponseBuilder {
		return new AdminConsistencyCheckResponseBuilder($this->buildL10n());
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
