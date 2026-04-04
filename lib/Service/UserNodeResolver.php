<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class UserNodeResolver {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFileNodeById(string $uid, int $fileId): File {
		$nodes = $this->rootFolder->getById($fileId);
		$prefix = '/' . $uid . '/files/';
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			if (str_starts_with((string)$node->getPath(), $prefix)) {
				return $node;
			}
		}

		throw new NotFoundException('File not found by ID.');
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFolderNodeById(string $uid, int $folderId): Folder {
		$nodes = $this->rootFolder->getById($folderId);
		$prefix = '/' . $uid . '/files/';
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			if (str_starts_with((string)$node->getPath(), $prefix)) {
				return $node;
			}
		}

		throw new NotFoundException('Folder not found by ID.');
	}

	/**
	 * @throws NotFoundException
	 */
	public function toUserAbsolutePath(string $uid, File $node): string {
		$nodePath = (string)$node->getPath();
		$prefix = '/' . $uid . '/files/';
		if (!str_starts_with($nodePath, $prefix)) {
			throw new NotFoundException('Cannot map file to user file tree.');
		}

		return '/' . ltrim(substr($nodePath, strlen($prefix)), '/');
	}
}
