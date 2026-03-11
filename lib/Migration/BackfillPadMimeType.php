<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\IDBConnection;

class BackfillPadMimeType implements IRepairStep {
	private const MIME_PAD = 'application/x-etherpad-nextcloud';
	private const MIME_PART = 'application';

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function getName(): string {
		return 'Backfill MIME type for existing .pad files';
	}

	public function run(IOutput $output): void {
		$padMimeId = $this->getMimeId(self::MIME_PAD);
		if ($padMimeId === null) {
			$output->info('Skipping MIME backfill: application/x-etherpad-nextcloud is not registered.');
			return;
		}

		$mimePartId = $this->getMimeId(self::MIME_PART);
		if ($mimePartId === null) {
			$output->info('Skipping MIME backfill: application mimepart is missing.');
			return;
		}

		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder
			->update('filecache')
			->set('mimetype', $queryBuilder->createNamedParameter($padMimeId))
			->set('mimepart', $queryBuilder->createNamedParameter($mimePartId))
			->where(
				$queryBuilder->expr()->like(
					'name',
					$queryBuilder->createNamedParameter('%.pad'),
				),
			)
			->andWhere(
				$queryBuilder->expr()->neq(
					'mimetype',
					$queryBuilder->createNamedParameter($padMimeId),
				),
			);

		$updated = $queryBuilder->executeStatement();
		$output->info(sprintf('Backfilled MIME type for %d .pad files.', $updated));
	}

	private function getMimeId(string $mime): ?int {
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder
			->select('id')
			->from('mimetypes')
			->where(
				$queryBuilder->expr()->eq(
					'mimetype',
					$queryBuilder->createNamedParameter($mime),
				),
			)
			->setMaxResults(1);

		$result = $queryBuilder->executeQuery();
		$rawValue = $result->fetchOne();
		$result->closeCursor();

		if ($rawValue === false) {
			return null;
		}

		return (int)$rawValue;
	}
}
