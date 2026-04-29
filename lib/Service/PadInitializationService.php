<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Files\NotFoundException;

class PadInitializationService {
	public const STATUS_ALREADY_INITIALIZED = 'already_initialized';
	public const STATUS_INITIALIZED = 'initialized';

	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadBootstrapService $padBootstrapService,
	) {
	}

	/**
	 * @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string}
	 * @throws NotFoundException
	 */
	public function initializeByPath(string $uid, string $file): array {
		$path = $this->padPaths->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new \InvalidArgumentException('Invalid file path.');
		}

		$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $path);
		return $this->initializeNode($uid, $node);
	}

	/**
	 * @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string}
	 * @throws NotFoundException
	 */
	public function initializeById(string $uid, int $fileId): array {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		return $this->initializeNode($uid, $node);
	}

	/** @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string} */
	private function initializeNode(string $uid, File $node): array {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		return $this->initialize($uid, $node, (string)$node->getContent());
	}

	/** @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string} */
	public function initialize(string $uid, File $file, string $content): array {
		$fileId = (int)$file->getId();
		$path = $this->userNodeResolver->toUserAbsolutePath($uid, $file);
		try {
			$parsed = $this->padFileService->parsePadFile($content);
			$meta = $parsed['frontmatter'];
			return [
				'status' => self::STATUS_ALREADY_INITIALIZED,
				'file' => $path,
				'file_id' => $fileId,
				'pad_id' => (string)$meta['pad_id'],
				'access_mode' => (string)$meta['access_mode'],
			];
		} catch (MissingFrontmatterException) {
			// Explicitly continue with bootstrap flow for legacy or empty .pad files.
		} catch (PadFileFormatException $e) {
			throw $e;
		}

		$this->padBootstrapService->initializeMissingFrontmatter($file, $content);
		$updatedContent = (string)$file->getContent();
		$parsed = $this->padFileService->parsePadFile((string)$updatedContent);
		$meta = $parsed['frontmatter'];

		return [
			'status' => self::STATUS_INITIALIZED,
			'file' => $path,
			'file_id' => $fileId,
			'pad_id' => (string)$meta['pad_id'],
			'access_mode' => (string)$meta['access_mode'],
		];
	}
}
