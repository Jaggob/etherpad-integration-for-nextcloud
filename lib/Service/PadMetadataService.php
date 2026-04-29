<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadMetadataService {
	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 * @throws LockedException
	 */
	public function metaById(string $uid, int $fileId): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->buildMeta($node, $absolutePath);
	}

	/** @return array<string,mixed> */
	public function resolve(string $uid, int $fileId = 0, string $file = ''): array {
		$resolvedFileId = $fileId;
		if ($resolvedFileId > 0) {
			try {
				$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $resolvedFileId);
				$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
			} catch (NotFoundException) {
				return ['is_pad' => false, 'file_id' => $resolvedFileId];
			}
		} else {
			$requestedPath = $this->padPaths->normalizeViewerFilePath($file);
			if ($requestedPath === '') {
				throw new \InvalidArgumentException('Invalid file path.');
			}

			try {
				$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $requestedPath);
			} catch (NotFoundException) {
				return ['is_pad' => false, 'path' => $requestedPath];
			}

			$resolvedFileId = (int)$node->getId();
			if ($resolvedFileId <= 0) {
				return ['is_pad' => false, 'path' => $requestedPath];
			}
			$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		}

		if (!str_ends_with(strtolower($normalizedPath), '.pad')) {
			return ['is_pad' => false, 'file_id' => $resolvedFileId, 'path' => $normalizedPath];
		}

		return $this->buildResolve($node, $resolvedFileId, $normalizedPath);
	}

	/**
	 * @return array<string,mixed>
	 * @throws LockedException
	 */
	private function buildMeta(File $node, string $absolutePath): array {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		if (!str_ends_with(strtolower($absolutePath), '.pad')) {
			return [
				'is_pad' => false,
				'file_id' => $fileId,
				'name' => $node->getName(),
				'path' => $absolutePath,
			];
		}

		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, true, 'Pad meta parse skipped');

		return [
			'is_pad' => true,
			'is_pad_mime' => (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			'file_id' => $fileId,
			'name' => $node->getName(),
			'path' => $absolutePath,
			'access_mode' => $metadata['access_mode'],
			'is_external' => $metadata['is_external'],
			'pad_id' => $metadata['pad_id'],
			'pad_url' => $metadata['pad_url'],
			'public_open_url' => $metadata['public_open_url'],
		];
	}

	/** @return array<string,mixed> */
	private function buildResolve(File $node, int $fileId, string $absolutePath): array {
		$metadata = $this->readPadMetadata($node, $fileId, $absolutePath, false, 'Pad resolve metadata parse skipped');

		return [
			'is_pad' => true,
			'is_pad_mime' => (string)$node->getMimeType() === 'application/x-etherpad-nextcloud',
			'file_id' => $fileId,
			'path' => $absolutePath,
			'access_mode' => $metadata['access_mode'],
			'is_external' => $metadata['is_external'],
			'public_open_url' => $metadata['public_open_url'],
		];
	}

	/** @return array{access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string} */
	private function readPadMetadata(File $node, int $fileId, string $absolutePath, bool $retryLockedRead, string $logMessage): array {
		$accessMode = '';
		$isExternal = false;
		$publicOpenUrl = '';
		$padUrl = '';
		$padId = '';

		try {
			$content = $retryLockedRead
				? $this->lockRetryService->readContentWithOpenLockRetry($node)
				: (string)$node->getContent();
			$parsed = $this->padFileService->parsePadFile((string)$content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);

			if ($accessMode === BindingService::ACCESS_PUBLIC) {
				$publicOpenUrl = $this->resolvePublicOpenUrl($padId, $padUrl, $isExternal);
				if ($publicOpenUrl !== '') {
					$padUrl = $publicOpenUrl;
				}
			}
		} catch (LockedException $e) {
			if ($retryLockedRead) {
				throw $e;
			}
			$this->logSkippedMetadata($logMessage, $fileId, $absolutePath, $e);
		} catch (\Throwable $e) {
			$this->logSkippedMetadata($logMessage, $fileId, $absolutePath, $e);
		}

		return [
			'access_mode' => $accessMode,
			'is_external' => $isExternal,
			'pad_id' => $padId,
			'pad_url' => $padUrl,
			'public_open_url' => $publicOpenUrl,
		];
	}

	private function resolvePublicOpenUrl(string $padId, string $padUrl, bool $isExternal): string {
		if ($isExternal && $padUrl !== '') {
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			return (string)$normalized['pad_url'];
		}
		if ($padId !== '') {
			return $this->etherpadClient->buildPadUrl($padId);
		}
		return '';
	}

	private function logSkippedMetadata(string $message, int $fileId, string $absolutePath, \Throwable $e): void {
		$this->logger->debug($message, [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'path' => $absolutePath,
			'exception' => $e,
		]);
	}
}
