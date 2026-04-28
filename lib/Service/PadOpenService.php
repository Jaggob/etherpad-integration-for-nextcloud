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

class PadOpenService {
	public function __construct(
		private PadFileService $padFileService,
		private PadFileOperationService $padFileOperations,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function openByPath(string $uid, string $displayName, string $file): array {
		$path = $this->padFileOperations->normalizeViewerFilePath($file);
		$node = $this->padFileOperations->resolveUserPadNode($uid, $path);
		$absolutePath = $this->padFileOperations->toUserAbsolutePath($uid, $node);
		return $this->openNode($uid, $displayName, $node, $absolutePath);
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function openById(string $uid, string $displayName, int $fileId): array {
		$node = $this->padFileOperations->resolveUserPadNodeById($uid, $fileId);
		$absolutePath = $this->padFileOperations->toUserAbsolutePath($uid, $node);
		return $this->openNode($uid, $displayName, $node, $absolutePath);
	}

	/**
	 * @return array<string,mixed>
	 * @throws BindingException
	 * @throws EtherpadClientException
	 * @throws LockedException
	 * @throws PadFileFormatException
	 */
	private function openNode(string $uid, string $displayName, File $node, string $absolutePath): array {
		try {
			$content = $this->padFileOperations->readContentWithOpenLockRetry($node);
			$fileId = (int)$node->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve file ID.');
			}

			$parsed = $this->padFileService->parsePadFile((string)$content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);
			$snapshotText = $isExternal ? $this->padFileService->getTextSnapshotForRestore((string)$content) : '';
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);

			return $this->buildOpenContext(
				$uid,
				$displayName,
				$absolutePath,
				$fileId,
				$padId,
				$accessMode,
				$padUrl,
				$isExternal,
				$snapshotText
			);
		} catch (LockedException $e) {
			$this->logger->warning('Pad open deferred because .pad file is locked', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$node->getId(),
				'path' => $absolutePath,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/** @return array<string,mixed> */
	private function buildOpenContext(
		string $uid,
		string $displayName,
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		string $padUrl = '',
		bool $isExternal = false,
		string $snapshotText = ''
	): array {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		$effectivePadUrl = '';
		$originalPadUrl = '';

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} else {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		}

		$cookieHeader = '';
		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			$openContext = $this->padSessionService->createProtectedOpenContext($uid, $displayName, $padId, 3600);
			$url = $openContext['url'];
			$cookieHeader = $this->padSessionService->buildSetCookieHeader($openContext['cookie']);
		} else {
			$url = $effectivePadUrl;
		}

		return [
			'file' => $path,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => $accessMode,
			'pad_url' => $effectivePadUrl,
			'is_external' => $isExternal,
			'original_pad_url' => $originalPadUrl,
			'snapshot_text' => $isExternal ? $snapshotText : '',
			'url' => $url,
			'cookie_header' => $cookieHeader,
		];
	}
}
