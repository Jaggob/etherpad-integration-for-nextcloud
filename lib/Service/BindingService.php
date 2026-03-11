<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class BindingService {
	public const TABLE = 'ep_pad_bindings';
	public const ACCESS_PUBLIC = 'public';
	public const ACCESS_PROTECTED = 'protected';
	public const STATE_ACTIVE = 'active';
	public const STATE_TRASHED = 'trashed';
	public const STATE_PURGED = 'purged';
	public const STATE_PENDING_DELETE = 'pending_delete';

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string,mixed>|null */
	public function findByFileId(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
	}

	/** @return array<string,mixed>|null */
	public function findByPadId(string $padId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('pad_id', $qb->createNamedParameter($padId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
	}

	/** @return array<int,array<string,mixed>> */
	public function findByState(string $state, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('updated_at', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	public function findTrashedWithoutFile(int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')
			->from(self::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter(self::STATE_TRASHED)))
			->andWhere($qb->expr()->isNull('fc.fileid'))
			->orderBy('b.updated_at', 'ASC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return is_array($rows) ? $rows : [];
	}

	public function countByState(string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row) || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	public function countTrashedWithoutFile(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(self::TABLE, 'b')
			->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
			->where($qb->expr()->eq('b.state', $qb->createNamedParameter(self::STATE_TRASHED)))
			->andWhere($qb->expr()->isNull('fc.fileid'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !isset($row['cnt'])) {
			return 0;
		}
		return max(0, (int)$row['cnt']);
	}

	public function hasFileCacheEntry(int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!is_array($row) || !isset($row['cnt'])) {
			return false;
		}
		return (int)$row['cnt'] > 0;
	}

	public function createBinding(int $fileId, string $padId, string $accessMode): void {
		$this->assertAccessMode($accessMode);
		$now = time();

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'pad_id' => $qb->createNamedParameter($padId),
				'access_mode' => $qb->createNamedParameter($accessMode),
				'state' => $qb->createNamedParameter(self::STATE_ACTIVE),
				'deleted_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);

		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->error('Could not create pad binding', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $e,
			]);
			throw new BindingException('Could not create unique pad binding.', 0, $e);
		}
	}

	public function assertConsistentMapping(int $fileId, string $padId, string $accessMode): void {
		$this->assertAccessMode($accessMode);
		$binding = $this->findByFileId($fileId);
		if ($binding === null) {
			throw new BindingException('No binding exists for this file.');
		}
		if ((string)$binding['pad_id'] !== $padId) {
			throw new BindingException('Binding pad ID mismatch.');
		}
		if ((string)$binding['access_mode'] !== $accessMode) {
			throw new BindingException('Binding access mode mismatch.');
		}
		if ((string)$binding['state'] !== self::STATE_ACTIVE) {
			throw new BindingException('Pad binding is not active.');
		}
	}

	public function markTrashed(int $fileId, int $deletedAtTs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::STATE_TRASHED))
			->set('deleted_at', $qb->createNamedParameter($deletedAtTs, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)));
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while marking trashed (expected active).');
		}
	}

	public function markRestored(int $fileId, string $newPadId): void {
		$qb = $this->db->getQueryBuilder();
		$stateExpr = $qb->expr()->orX(
			$qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_TRASHED)),
			$qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)),
		);
		$qb->update(self::TABLE)
			->set('pad_id', $qb->createNamedParameter($newPadId))
			->set('state', $qb->createNamedParameter(self::STATE_ACTIVE))
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($stateExpr);
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while restoring (expected trashed or pending_delete).');
		}
	}

	public function markPendingDelete(int $fileId, int $deletedAtTs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE))
			->set('deleted_at', $qb->createNamedParameter($deletedAtTs, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_TRASHED)));
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while marking pending_delete (expected trashed).');
		}
	}

	public function markPendingDeleteResolved(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::STATE_TRASHED))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)));
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while resolving pending_delete (expected pending_delete).');
		}
	}

	public function markPurged(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$allowedStateExpr = $qb->expr()->orX(
			$qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_TRASHED)),
			$qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_PENDING_DELETE)),
		);
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::STATE_PURGED))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($allowedStateExpr);
		$updated = $qb->executeStatement();
		if ($updated < 1) {
			throw new BindingStateConflictException('State transition conflict while marking purged (expected trashed or pending_delete).');
		}
	}

	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function assertAccessMode(string $accessMode): void {
		if (!in_array($accessMode, [self::ACCESS_PUBLIC, self::ACCESS_PROTECTED], true)) {
			throw new BindingException('Unsupported access mode: ' . $accessMode);
		}
	}
}
