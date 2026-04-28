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

class PadInitializationService {
	public function __construct(
		private PadFileService $padFileService,
		private PadFileOperationService $padFileOperations,
		private PadBootstrapService $padBootstrapService,
	) {
	}

	/** @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string} */
	public function initialize(string $uid, File $file, string $content): array {
		$fileId = (int)$file->getId();
		$path = $this->padFileOperations->toUserAbsolutePath($uid, $file);
		try {
			$parsed = $this->padFileService->parsePadFile($content);
			$meta = $parsed['frontmatter'];
			return [
				'status' => 'already_initialized',
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
			'status' => 'initialized',
			'file' => $path,
			'file_id' => $fileId,
			'pad_id' => (string)$meta['pad_id'],
			'access_mode' => (string)$meta['access_mode'],
		];
	}
}
