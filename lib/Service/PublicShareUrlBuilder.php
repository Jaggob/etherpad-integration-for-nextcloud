<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\IURLGenerator;

class PublicShareUrlBuilder {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private PathNormalizer $pathNormalizer,
	) {
	}

	public function buildShareBaseUrl(string $token): string {
		$webroot = rtrim($this->urlGenerator->getWebroot(), '/');
		return $webroot . '/s/' . rawurlencode($token);
	}

	public function buildShareRedirectUrl(string $token, mixed $fileParam): string {
		$base = $this->buildShareBaseUrl($token);
		$rawFile = is_scalar($fileParam) ? trim((string)$fileParam) : '';
		if ($rawFile === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		try {
			$normalized = $this->pathNormalizer->normalizePublicShareFilePath($fileParam, $token);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Invalid file path.');
		}
		if ($normalized === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$path = trim($normalized, '/');
		if ($path === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$dir = dirname($path);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$fileName = basename($path);
		if ($fileName === '' || !str_ends_with(strtolower($fileName), '.pad')) {
			throw new \InvalidArgumentException('The selected file is not a .pad document.');
		}

		return $base . '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($fileName);
	}

	public function buildDownloadUrl(string $token, string $selectedRelativePath, bool $isFolderShare, string $fileName): string {
		$base = $this->buildShareBaseUrl($token) . '/download';
		if (!$isFolderShare) {
			return $base;
		}

		$path = trim($selectedRelativePath, '/');
		if ($path === '') {
			return '';
		}

		$dir = dirname($path);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$name = basename($path);
		if ($name === '') {
			$name = $fileName;
		}
		if ($name === '') {
			return '';
		}

		return $base . '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($name);
	}
}
