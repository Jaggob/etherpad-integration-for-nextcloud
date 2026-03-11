<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ConsistencyCheckService {
	private const DEFAULT_FRONTMATTER_CHUNK_SIZE = 200;
	private const DEFAULT_FRONTMATTER_TIME_BUDGET_MS = 3000;
	private const MIN_FRONTMATTER_CHUNK_SIZE = 50;
	private const MAX_FRONTMATTER_CHUNK_SIZE = 1000;
	private const MIN_FRONTMATTER_TIME_BUDGET_MS = 250;
	private const MAX_FRONTMATTER_TIME_BUDGET_MS = 60000;

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private PadFileService $padFileService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *   binding_without_file_count:int,
	 *   file_without_binding_count:int,
	 *   invalid_frontmatter_count:int,
	 *   frontmatter_scanned:int,
	 *   frontmatter_skipped:int,
	 *   frontmatter_scan_limit_reached:bool,
	 *   frontmatter_time_budget_exceeded:bool,
	 *   frontmatter_time_budget_ms:int,
	 *   frontmatter_chunk_size:int,
	 *   samples:array<string,array<int,array<string,mixed>>>
	 * }
	 */
	public function run(
		int $frontmatterScanLimit = 1500,
		int $sampleLimit = 25,
		int $frontmatterChunkSize = self::DEFAULT_FRONTMATTER_CHUNK_SIZE,
		int $frontmatterTimeBudgetMs = self::DEFAULT_FRONTMATTER_TIME_BUDGET_MS,
	): array {
		$scanLimit = max(1, $frontmatterScanLimit);
		$limit = max(1, $sampleLimit);
		$chunkSize = min(
			self::MAX_FRONTMATTER_CHUNK_SIZE,
			max(self::MIN_FRONTMATTER_CHUNK_SIZE, $frontmatterChunkSize)
		);
		$timeBudgetMs = min(
			self::MAX_FRONTMATTER_TIME_BUDGET_MS,
			max(self::MIN_FRONTMATTER_TIME_BUDGET_MS, $frontmatterTimeBudgetMs)
		);

		$bindingWithoutFileCount = $this->countBindingsWithoutFile();
		$fileWithoutBindingCount = $this->countPadFilesWithoutBinding();

		$sampleBindingsWithoutFile = $this->sampleBindingsWithoutFile($limit);
		$sampleFilesWithoutBinding = $this->samplePadFilesWithoutBinding($limit);
		$frontmatterResult = $this->checkFrontmatterConsistency($scanLimit, $limit, $chunkSize, $timeBudgetMs);

		return [
			'binding_without_file_count' => $bindingWithoutFileCount,
			'file_without_binding_count' => $fileWithoutBindingCount,
			'invalid_frontmatter_count' => $frontmatterResult['invalid_count'],
			'frontmatter_scanned' => $frontmatterResult['scanned'],
			'frontmatter_skipped' => $frontmatterResult['skipped'],
			'frontmatter_scan_limit_reached' => $frontmatterResult['scan_limit_reached'],
			'frontmatter_time_budget_exceeded' => $frontmatterResult['time_budget_exceeded'],
			'frontmatter_time_budget_ms' => $timeBudgetMs,
			'frontmatter_chunk_size' => $chunkSize,
			'samples' => [
				'bindings_without_file' => $sampleBindingsWithoutFile,
				'files_without_binding' => $sampleFilesWithoutBinding,
				'invalid_frontmatter' => $frontmatterResult['samples'],
			],
		];
	}

	private function countBindingsWithoutFile(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	private function countPadFilesWithoutBinding(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from('filecache', 'fc')
			->leftJoin('fc', BindingService::TABLE, 'b', $qb->expr()->eq('fc.fileid', 'b.file_id'))
			->where($qb->expr()->isNull('b.file_id'))
			->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')))
			->andWhere($qb->expr()->like('fc.name', $qb->createNamedParameter('%.pad')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	/** @return array<int,array<string,mixed>> */
	private function sampleBindingsWithoutFile(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.access_mode', 'b.state')
			->from(BindingService::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->isNull('fc.fileid'))
			->orderBy('b.file_id', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return is_array($rows) ? $rows : [];
	}

	/** @return array<int,array<string,mixed>> */
	private function samplePadFilesWithoutBinding(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fc.fileid', 'fc.path')
			->from('filecache', 'fc')
			->leftJoin('fc', BindingService::TABLE, 'b', $qb->expr()->eq('fc.fileid', 'b.file_id'))
			->where($qb->expr()->isNull('b.file_id'))
			->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')))
			->andWhere($qb->expr()->like('fc.name', $qb->createNamedParameter('%.pad')))
			->orderBy('fc.fileid', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		if (!is_array($rows)) {
			return [];
		}

		$samples = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$samples[] = [
				'file_id' => (int)($row['fileid'] ?? 0),
				'path' => '/' . ltrim((string)($row['path'] ?? ''), '/'),
			];
		}
		return $samples;
	}

	/**
	 * @return array{
	 *   invalid_count:int,
	 *   scanned:int,
	 *   skipped:int,
	 *   scan_limit_reached:bool,
	 *   time_budget_exceeded:bool,
	 *   samples:array<int,array<string,mixed>>
	 * }
	 */
	private function checkFrontmatterConsistency(
		int $scanLimit,
		int $sampleLimit,
		int $chunkSize,
		int $timeBudgetMs,
	): array {
		$invalidCount = 0;
		$scanned = 0;
		$skipped = 0;
		$samples = [];
		$processed = 0;
		$lastFileId = 0;
		$scanLimitReached = false;
		$timeBudgetExceeded = false;
		$startedAt = microtime(true);

		while ($processed < $scanLimit) {
			$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
			if ($elapsedMs >= $timeBudgetMs) {
				$timeBudgetExceeded = true;
				break;
			}

			$remaining = $scanLimit - $processed;
			$batchSize = min($chunkSize, $remaining);
			$rows = $this->fetchFrontmatterRowsAfterFileId($lastFileId, $batchSize);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				if (!is_array($row)) {
					continue;
				}

				$fileId = (int)($row['file_id'] ?? 0);
				$path = '/' . ltrim((string)($row['path'] ?? ''), '/');
				$expectedPadId = (string)($row['pad_id'] ?? '');
				$expectedAccessMode = (string)($row['access_mode'] ?? '');
				if ($fileId <= 0 || $expectedPadId === '' || $expectedAccessMode === '') {
					continue;
				}

				$lastFileId = max($lastFileId, $fileId);
				$processed++;

				$fileNode = $this->resolvePadFileNode($fileId);
				if ($fileNode === null) {
					$skipped++;
					if ($processed >= $scanLimit) {
						$scanLimitReached = true;
						break;
					}
					continue;
				}

				$scanned++;

				try {
					$content = (string)$fileNode->getContent();
					$parsed = $this->padFileService->parsePadFile($content);
					$frontmatter = $parsed['frontmatter'];

					$mismatchReasons = [];
					if ((int)($frontmatter['file_id'] ?? -1) !== $fileId) {
						$mismatchReasons[] = 'file_id_mismatch';
					}
					if ((string)($frontmatter['pad_id'] ?? '') !== $expectedPadId) {
						$mismatchReasons[] = 'pad_id_mismatch';
					}
					if ((string)($frontmatter['access_mode'] ?? '') !== $expectedAccessMode) {
						$mismatchReasons[] = 'access_mode_mismatch';
					}

					if ($mismatchReasons !== []) {
						$invalidCount++;
						if (count($samples) < $sampleLimit) {
							$samples[] = [
								'file_id' => $fileId,
								'path' => $path,
								'reason' => implode(',', $mismatchReasons),
							];
						}
					}
				} catch (PadFileFormatException $e) {
					$invalidCount++;
					if (count($samples) < $sampleLimit) {
						$samples[] = [
							'file_id' => $fileId,
							'path' => $path,
							'reason' => 'invalid_frontmatter',
							'detail' => $e->getMessage(),
						];
					}
				} catch (\Throwable $e) {
					$skipped++;
					$this->logger->debug('Consistency check skipped .pad frontmatter read due to file read error.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'path' => $path,
						'exception' => $e,
					]);
				}

				if ($processed >= $scanLimit) {
					$scanLimitReached = true;
					break;
				}
			}
		}

		if ($timeBudgetExceeded) {
			$this->logger->info('Consistency check frontmatter scan hit time budget; result is partial.', [
				'app' => 'etherpad_nextcloud',
				'scan_limit' => $scanLimit,
				'chunk_size' => $chunkSize,
				'time_budget_ms' => $timeBudgetMs,
				'processed' => $processed,
				'scanned' => $scanned,
				'skipped' => $skipped,
			]);
		}

		return [
			'invalid_count' => $invalidCount,
			'scanned' => $scanned,
			'skipped' => $skipped,
			'scan_limit_reached' => $scanLimitReached,
			'time_budget_exceeded' => $timeBudgetExceeded,
			'samples' => $samples,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function fetchFrontmatterRowsAfterFileId(int $afterFileId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.file_id', 'b.pad_id', 'b.access_mode', 'fc.path')
			->from(BindingService::TABLE, 'b')
			->innerJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->gt('b.file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')))
			->andWhere($qb->expr()->like('fc.name', $qb->createNamedParameter('%.pad')))
			->orderBy('b.file_id', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return is_array($rows) ? $rows : [];
	}

	private function resolvePadFileNode(int $fileId): ?File {
		try {
			$nodes = $this->rootFolder->getById($fileId);
		} catch (\Throwable) {
			return null;
		}

		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			if (str_ends_with(strtolower($node->getName()), '.pad')) {
				return $node;
			}
		}

		return null;
	}
}
