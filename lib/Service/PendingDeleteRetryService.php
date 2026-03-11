<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingStateConflictException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use Psr\Log\LoggerInterface;

class PendingDeleteRetryService {
	public function __construct(
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *   attempted:int,
	 *   resolved:int,
	 *   failed:int,
	 *   remaining:int,
	 *   trashed_attempted:int,
	 *   trashed_resolved:int,
	 *   trashed_failed:int,
	 *   trashed_without_file_remaining:int
	 * }
	 */
	public function retry(int $limit = 200): array {
		$safeLimit = max(1, $limit);
		$pendingResult = $this->retryPendingDeletes($safeLimit);
		$trashedResult = $this->retryTrashedWithoutFileDeletes($safeLimit);

		return [
			'attempted' => $pendingResult['attempted'],
			'resolved' => $pendingResult['resolved'],
			'failed' => $pendingResult['failed'],
			'remaining' => $this->countPendingDeletes(),
			'trashed_attempted' => $trashedResult['attempted'],
			'trashed_resolved' => $trashedResult['resolved'],
			'trashed_failed' => $trashedResult['failed'],
			'trashed_without_file_remaining' => $this->countTrashedWithoutFile(),
		];
	}

	public function countPendingDeletes(): int {
		return $this->bindingService->countByState(BindingService::STATE_PENDING_DELETE);
	}

	public function countTrashedWithoutFile(): int {
		return $this->bindingService->countTrashedWithoutFile();
	}

	/** @return array{attempted:int,resolved:int,failed:int} */
	private function retryPendingDeletes(int $limit): array {
		$attempted = 0;
		$resolved = 0;
		$failed = 0;
		$pending = $this->bindingService->findByState(BindingService::STATE_PENDING_DELETE, $limit);
		foreach ($pending as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			$attempted++;
			$fileExists = $this->bindingService->hasFileCacheEntry($fileId);
			try {
				$this->etherpadClient->deletePad($padId);
				if ($this->markDeleteResolved($fileId, $padId, $fileExists, false)) {
					$resolved++;
				}
				continue;
			} catch (\Throwable $e) {
				if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
					if ($this->markDeleteResolved($fileId, $padId, $fileExists, true)) {
						$resolved++;
					}
					continue;
				}
				$failed++;
				$this->logger->warning('Pending pad delete retry failed.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
					'exception' => $e,
				]);
			}
		}

		return [
			'attempted' => $attempted,
			'resolved' => $resolved,
			'failed' => $failed,
		];
	}

	/** @return array{attempted:int,resolved:int,failed:int} */
	private function retryTrashedWithoutFileDeletes(int $limit): array {
		$attempted = 0;
		$resolved = 0;
		$failed = 0;
		$trashedWithoutFile = $this->bindingService->findTrashedWithoutFile($limit);
		foreach ($trashedWithoutFile as $row) {
			$fileId = (int)($row['file_id'] ?? 0);
			$padId = (string)($row['pad_id'] ?? '');
			if ($fileId <= 0 || $padId === '') {
				continue;
			}
			$attempted++;

			try {
				$this->etherpadClient->deletePad($padId);
			} catch (\Throwable $e) {
				if (!EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
					$failed++;
					$this->logger->warning('Trashed binding cleanup failed while deleting pad.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $e,
					]);
					continue;
				}
			}

			try {
				$this->bindingService->markPurged($fileId);
				$resolved++;
				$this->logger->info('Purged trashed binding after trashbin file removal.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
				]);
			} catch (BindingStateConflictException $e) {
				$this->logger->debug('Skipped trashed binding purge due to state transition conflict.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => $fileId,
					'padId' => $padId,
					'exception' => $e,
				]);
			}
		}

		return [
			'attempted' => $attempted,
			'resolved' => $resolved,
			'failed' => $failed,
		];
	}

	private function markDeleteResolved(int $fileId, string $padId, bool $fileExists, bool $alreadyDeleted): bool {
		try {
			if ($fileExists) {
				$this->bindingService->markPendingDeleteResolved($fileId);
			} else {
				$this->bindingService->markPurged($fileId);
			}
		} catch (BindingStateConflictException $e) {
			$this->logger->debug('Skipped pending delete resolve due to state transition conflict.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'exception' => $e,
			]);
			return false;
		}

		if ($alreadyDeleted) {
			$this->logger->info('Resolved pending delete because pad is already gone.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'padId' => $padId,
				'state' => $fileExists ? BindingService::STATE_TRASHED : BindingService::STATE_PURGED,
			]);
			return true;
		}

		$this->logger->info('Resolved pending pad delete.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'padId' => $padId,
			'state' => $fileExists ? BindingService::STATE_TRASHED : BindingService::STATE_PURGED,
		]);
		return true;
	}

}
