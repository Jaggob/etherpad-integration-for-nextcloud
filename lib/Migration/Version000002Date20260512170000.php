<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use Closure;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @psalm-api
 */
class Version000002Date20260512170000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable(BindingService::TABLE)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete(BindingService::TABLE)
			->where($qb->expr()->in(
				'state',
				$qb->createNamedParameter(['trashed', 'purged'], IQueryBuilder::PARAM_STR_ARRAY)
			));
		$qb->executeStatement();
	}
}
