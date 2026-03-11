<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Util;

use InvalidArgumentException;

class PathNormalizer {
	public function normalizeViewerFilePath(mixed $fileParam): string {
		if (!is_string($fileParam)) {
			throw new InvalidArgumentException('Invalid file parameter.');
		}

		$path = trim(urldecode($fileParam));
		if ($path === '') {
			return '';
		}

		if (preg_match('#^https?://#i', $path) === 1) {
			$path = $this->normalizeDavUrlToPath($path);
		}

		$path = str_replace('\\', '/', $path);
		$path = preg_replace('/\s+\.pad$/i', '.pad', $path) ?? $path;
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}

		$normalized = $this->normalizeSegments(ltrim($path, '/'));
		if ($normalized === '') {
			throw new InvalidArgumentException('Invalid file path.');
		}

		return '/' . $normalized;
	}

	public function normalizePublicShareFilePath(mixed $fileParam, string $shareToken): string {
		if (!is_string($fileParam)) {
			throw new InvalidArgumentException('Invalid file parameter.');
		}

		$path = trim(urldecode($fileParam));
		if ($path === '') {
			return '';
		}

		if (preg_match('#^https?://#i', $path) === 1) {
			$rawPath = (string)(parse_url($path, PHP_URL_PATH) ?? '');
			if (preg_match('#/public\.php/dav/files/([^/]+)/(.*)$#', urldecode($rawPath), $matches) === 1) {
				if ($matches[1] !== $shareToken) {
					throw new InvalidArgumentException('Share token mismatch in public file path.');
				}
				$path = $matches[2];
			} elseif (preg_match('#/remote\.php/dav/files/[^/]+/(.*)$#', urldecode($rawPath), $matches) === 1) {
				$path = $matches[1];
			} else {
				$path = ltrim(urldecode($rawPath), '/');
			}
		}

		$path = $this->normalizeSegments($path);
		return $path;
	}

	private function normalizeDavUrlToPath(string $url): string {
		$rawPath = (string)(parse_url($url, PHP_URL_PATH) ?? '');
		$decodedPath = urldecode($rawPath);

		if (preg_match('#/remote\.php/dav/files/[^/]+/(.+)$#', $decodedPath, $matches) === 1) {
			return '/' . ltrim($matches[1], '/');
		}
		if (preg_match('#/public\.php/dav/files/[^/]+/(.+)$#', $decodedPath, $matches) === 1) {
			return '/' . ltrim($matches[1], '/');
		}

		return $decodedPath;
	}

	private function normalizeSegments(string $path): string {
		$path = str_replace('\\', '/', $path);
		$segments = explode('/', ltrim($path, '/'));
		$safe = [];
		foreach ($segments as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new InvalidArgumentException('Path traversal is not allowed.');
			}
			$safe[] = $segment;
		}
		return implode('/', $safe);
	}
}
