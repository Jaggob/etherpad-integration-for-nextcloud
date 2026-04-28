<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PathNormalizer;

class PadPathService {
	public function __construct(
		private PathNormalizer $pathNormalizer,
	) {
	}

	public function normalizeViewerFilePath(string $file): string {
		return $this->pathNormalizer->normalizeViewerFilePath($file);
	}

	public function normalizeCreatePath(string $file): string {
		$path = $this->pathNormalizer->normalizeViewerFilePath($file);
		if ($path === '') {
			throw new \InvalidArgumentException('Invalid file path.');
		}
		if (!str_ends_with(strtolower($path), '.pad')) {
			$path .= '.pad';
		}
		return $path;
	}

	public function normalizeCreateFileName(string $name): string {
		$fileName = trim($name);
		$fileName = preg_replace('/\s+\.pad$/i', '.pad', $fileName) ?? $fileName;
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			throw new \InvalidArgumentException('Invalid file name.');
		}
		if (str_contains($fileName, '/') || str_contains($fileName, '\\')) {
			throw new \InvalidArgumentException('Invalid file name.');
		}
		if (!str_ends_with(strtolower($fileName), '.pad')) {
			$fileName .= '.pad';
		}
		return $fileName;
	}
}
