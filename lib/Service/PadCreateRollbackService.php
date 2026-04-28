<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class PadCreateRollbackService {
	public function __construct(
		private IRootFolder $rootFolder,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
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
