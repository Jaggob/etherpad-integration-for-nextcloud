<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\IL10N;

class AdminConsistencyCheckResponseBuilder {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function build(array $result): array {
		$issues = (int)$result['binding_without_file_count']
			+ (int)$result['file_without_binding_count']
			+ (int)$result['invalid_frontmatter_count'];
		$timeBudgetExceeded = (bool)($result['frontmatter_time_budget_exceeded'] ?? false);
		$scanLimitReached = (bool)($result['frontmatter_scan_limit_reached'] ?? false);
		$isPartial = $timeBudgetExceeded || $scanLimitReached;
		$message = $issues > 0
			? $this->l10n->t('Consistency check finished with issues.')
			: $this->l10n->t('Consistency check successful. No issues found.');
		if ($isPartial) {
			$message .= ' ' . $this->l10n->t('Frontmatter validation result is partial (scan limit/time budget reached).');
		}

		return [
			'ok' => true,
			'message' => $message,
			'binding_without_file_count' => (int)$result['binding_without_file_count'],
			'file_without_binding_count' => (int)$result['file_without_binding_count'],
			'invalid_frontmatter_count' => (int)$result['invalid_frontmatter_count'],
			'frontmatter_scanned' => (int)$result['frontmatter_scanned'],
			'frontmatter_skipped' => (int)$result['frontmatter_skipped'],
			'frontmatter_scan_limit_reached' => $scanLimitReached,
			'frontmatter_time_budget_exceeded' => $timeBudgetExceeded,
			'frontmatter_time_budget_ms' => (int)($result['frontmatter_time_budget_ms'] ?? 0),
			'frontmatter_chunk_size' => (int)($result['frontmatter_chunk_size'] ?? 0),
			'samples' => $result['samples'],
		];
	}
}
