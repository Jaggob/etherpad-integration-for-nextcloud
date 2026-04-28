<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadFileOperationService {
	private const OPEN_LOCK_RETRY_DELAYS_US = [100000, 200000, 400000];
	private const SYNC_LOCK_RETRY_DELAYS_US = [150000, 300000, 600000];

	public function __construct(
		private PathNormalizer $pathNormalizer,
		private IRootFolder $rootFolder,
		private UserNodeResolver $userNodeResolver,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	public function normalizeViewerFilePath(string $file): string {
		return $this->pathNormalizer->normalizeViewerFilePath($file);
	}

	public function normalizeCreatePath(string $file): string {
		$path = $this->pathNormalizer->normalizeViewerFilePath($file);
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

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserPadNode(string $uid, string $absolutePath): File {
		return $this->userNodeResolver->resolveUserFileNodeByPath($uid, $absolutePath);
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserPadNodeById(string $uid, int $fileId): File {
		return $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveUserFolderNodeById(string $uid, int $folderId): Folder {
		return $this->userNodeResolver->resolveUserFolderNodeById($uid, $folderId);
	}

	/**
	 * @throws NotFoundException
	 */
	public function toUserAbsolutePath(string $uid, File $node): string {
		return $this->userNodeResolver->toUserAbsolutePath($uid, $node);
	}

	/**
	 * @throws \RuntimeException
	 */
	public function createUserFile(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new \RuntimeException('Invalid empty create path.');
		}

		$parentPath = dirname($relativePath);
		$fileName = basename($relativePath);
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			throw new \RuntimeException('Invalid target filename.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$parent = $parentPath === '.' ? $userFolder : $userFolder->get($parentPath);
		} catch (NotFoundException $e) {
			throw new \RuntimeException('Target parent folder does not exist.', 0, $e);
		}
		if (!$parent instanceof Folder) {
			throw new \RuntimeException('Target parent folder does not exist.');
		}

		return $this->createUserFileInFolder($parent, $fileName);
	}

	/**
	 * @throws \RuntimeException
	 */
	public function createUserFileInFolder(Folder $parent, string $fileName): File {
		try {
			$node = $parent->newFile($fileName);
		} catch (\Throwable $e) {
			if ($parent->nodeExists($fileName)) {
				throw new \RuntimeException('Target .pad file already exists.', Http::STATUS_CONFLICT, $e);
			}
			throw new \RuntimeException('Could not create .pad file.', 0, $e);
		}
		if (!$node instanceof File) {
			throw new \RuntimeException('Could not create .pad file.');
		}
		return $node;
	}

	public function rollbackFailedCreate(string $uid, string $path, string $padId, bool $fileCreated): void {
		try {
			if ($fileCreated || $this->userNodeExists($uid, $path)) {
				$this->deleteUserNodeIfExists($uid, $path);
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed .pad file create', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}

		if ($padId !== '') {
			try {
				$this->etherpadClient->deletePad($padId);
			} catch (\Throwable $cleanupError) {
				$this->logger->warning('Could not cleanup failed Etherpad create', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $cleanupError,
				]);
			}
		}
	}

	public function rollbackExternalCreate(string $uid, string $path, bool $fileCreated): void {
		try {
			if ($fileCreated || $this->userNodeExists($uid, $path)) {
				$this->deleteUserNodeIfExists($uid, $path);
			}
		} catch (\Throwable $cleanupError) {
			$this->logger->warning('Could not cleanup failed external .pad create', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'exception' => $cleanupError,
			]);
		}
	}

	public function buildExternalBindingPadId(string $origin, string $remotePadId, int $fileId): string {
		return 'ext.' . substr(hash('sha256', $origin . '|' . $remotePadId . '|' . $fileId), 0, 40);
	}

	public function isCreateConflict(\Throwable $e): bool {
		return $e->getCode() === Http::STATUS_CONFLICT;
	}

	public function readContentWithOpenLockRetry(File $node): string {
		foreach (self::OPEN_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				return (string)$node->getContent();
			} catch (LockedException) {
				\usleep($delay);
			}
		}

		return (string)$node->getContent();
	}

	public function putContentWithSyncLockRetry(File $node, string $content, int &$lockRetries): void {
		foreach (self::SYNC_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				$node->putContent($content);
				return;
			} catch (LockedException) {
				\usleep($delay);
				$lockRetries++;
			}
		}

		$node->putContent($content);
	}

	private function userNodeExists(string $uid, string $absolutePath): bool {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			return false;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			return $userFolder->nodeExists($relativePath);
		} catch (\Throwable) {
			return false;
		}
	}

	private function deleteUserNodeIfExists(string $uid, string $absolutePath): void {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			return;
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if (!$userFolder->nodeExists($relativePath)) {
			return;
		}
		$userFolder->get($relativePath)->delete();
	}
}
