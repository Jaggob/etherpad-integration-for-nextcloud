<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadSyncService {
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_UPDATED = 'updated';
	public const STATUS_LOCKED = 'locked';
	public const STATUS_SYNCED = 'synced';
	public const STATUS_OUT_OF_SYNC = 'out_of_sync';
	public const STATUS_UNAVAILABLE = 'unavailable';

	public function __construct(
		private PadFileService $padFileService,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function syncById(string $uid, int $fileId, bool $force): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			throw new \InvalidArgumentException('Selected file is not a .pad file.');
		}

		$padId = '';
		$accessMode = '';
		$isExternal = false;
		$lockRetries = 0;

		try {
			$currentContent = (string)$node->getContent();
			$parsed = $this->padFileService->parsePadFile((string)$currentContent);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);

			if ($isExternal) {
				return $this->syncExternalPad($node, $fileId, $padId, $padUrl, (string)$currentContent, $force, $lockRetries);
			}

			return $this->syncInternalPad($node, $fileId, $padId, (string)$currentContent, $force, $lockRetries);
		} catch (LockedException $e) {
			$this->logger->info('Pad sync deferred because .pad file is locked', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'padId' => $padId,
				'accessMode' => $accessMode,
				'external' => $isExternal,
				'force' => $force,
				'lockRetryAttempts' => $lockRetries,
				'exception' => $e,
			]);
			return [
				'status' => self::STATUS_LOCKED,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'external' => $isExternal,
				'forced' => $force,
				'lock_retries' => $lockRetries,
				'retryable' => true,
			];
		} catch (BindingException|PadFileFormatException|EtherpadClientException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'force' => $force,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function syncStatusById(string $uid, int $fileId): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);

		try {
			$content = (string)$node->getContent();
			$parsed = $this->padFileService->parsePadFile((string)$content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);

			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);
			if ($isExternal) {
				return [
					'status' => self::STATUS_UNAVAILABLE,
					'in_sync' => null,
					'reason' => 'external_no_revision',
				];
			}

			$currentRev = $this->etherpadClient->getRevisionsCount($padId);
			$snapshotRev = $this->padFileService->getSnapshotRevision((string)$content);
			$inSync = $snapshotRev >= $currentRev;

			return [
				'status' => $inSync ? self::STATUS_SYNCED : self::STATUS_OUT_OF_SYNC,
				'in_sync' => $inSync,
				'snapshot_rev' => $snapshotRev,
				'current_rev' => $currentRev,
			];
		} catch (BindingException|PadFileFormatException|EtherpadClientException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync status check failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/** @return array<string,mixed> */
	private function syncExternalPad(
		File $node,
		int $fileId,
		string $padId,
		string $padUrl,
		string $currentContent,
		bool $force,
		int &$lockRetries,
	): array {
		if ($padUrl === '') {
			throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
		}
		$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);

		// External sync already performs a live upstream text fetch on every call.
		// force=1 therefore only marks caller intent while preserving the no-blind-rewrite invariant.
		$text = $this->etherpadClient->getPublicTextFromPadUrl($normalized['pad_url']);

		$existingText = $this->padFileService->getTextSnapshotForRestore($currentContent);
		if ($existingText === $text) {
			return [
				'status' => self::STATUS_UNCHANGED,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'external' => true,
				'forced' => $force,
			];
		}

		$previousRev = $this->padFileService->getSnapshotRevision($currentContent);
		$nextRev = max(0, $previousRev + 1);
		$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $text, '', $nextRev, false);
		$this->lockRetryService->putContentWithSyncLockRetry($node, $updatedContent, $lockRetries);

		return [
			'status' => self::STATUS_UPDATED,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'external' => true,
			'forced' => $force,
			'snapshot_rev' => $nextRev,
			'lock_retries' => $lockRetries,
		];
	}

	/** @return array<string,mixed> */
	private function syncInternalPad(
		File $node,
		int $fileId,
		string $padId,
		string $currentContent,
		bool $force,
		int &$lockRetries,
	): array {
		$currentRev = $this->etherpadClient->getRevisionsCount($padId);
		$snapshotRev = $this->padFileService->getSnapshotRevision($currentContent);
		if (!$force && $snapshotRev >= $currentRev) {
			return [
				'status' => self::STATUS_UNCHANGED,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'external' => false,
				'forced' => false,
				'snapshot_rev' => $snapshotRev,
				'current_rev' => $currentRev,
			];
		}

		$text = $this->etherpadClient->getText($padId);
		$html = $this->etherpadClient->getHTML($padId);
		if ($force && $snapshotRev >= $currentRev) {
			// force=1 bypasses the cheap revision short-circuit and performs a live content re-check.
			$existingText = $this->padFileService->getTextSnapshotForRestore($currentContent);
			$existingHtml = $this->padFileService->getHtmlSnapshotForRestore($currentContent);
			if ($existingText === $text && $existingHtml === $html) {
				return [
					'status' => self::STATUS_UNCHANGED,
					'file_id' => $fileId,
					'pad_id' => $padId,
					'external' => false,
					'forced' => true,
					'snapshot_rev' => $snapshotRev,
					'current_rev' => $currentRev,
				];
			}
		}

		$updatedContent = $this->padFileService->withExportSnapshot($currentContent, $text, $html, $currentRev);
		$this->lockRetryService->putContentWithSyncLockRetry($node, $updatedContent, $lockRetries);

		return [
			'status' => self::STATUS_UPDATED,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'external' => false,
			'forced' => $force,
			'snapshot_rev' => $currentRev,
			'lock_retries' => $lockRetries,
		];
	}
}
