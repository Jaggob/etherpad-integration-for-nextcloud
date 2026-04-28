<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RestoreFromTrashListener implements IEventListener {
	public function __construct(
		private LifecycleService $lifecycleService,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getTarget')) {
			return;
		}

		$node = $event->getTarget();
		if (!$node instanceof File) {
			return;
		}

		$this->restoreNode($node);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function handleLegacyHook(array $params): void {
		$filePath = $params['filePath'] ?? null;
		if (!is_string($filePath) || trim($filePath) === '') {
			return;
		}

		$node = $this->resolveUserFileByPath($filePath);
		if (!$node instanceof File) {
			return;
		}

		$this->restoreNode($node);
	}

	private function restoreNode(File $node): void {
		try {
			$result = $this->lifecycleService->handleRestore($node);
			if (($result['status'] ?? '') === LifecycleService::RESULT_SKIPPED) {
				$this->logger->debug('RestoreFromTrash listener skipped lifecycle action.', [
					'app' => 'etherpad_nextcloud',
					'fileId' => (int)$node->getId(),
					'reason' => (string)($result['reason'] ?? 'unknown'),
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('RestoreFromTrash listener aborted due to lifecycle error', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$node->getId(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	private function resolveUserFileByPath(string $path): ?File {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		$relativePath = ltrim(trim($path), '/');
		$userFilesPrefix = $uid . '/files/';
		if (str_starts_with($relativePath, $userFilesPrefix)) {
			$relativePath = substr($relativePath, strlen($userFilesPrefix));
		}
		if (str_starts_with($relativePath, 'files/')) {
			$relativePath = substr($relativePath, strlen('files/'));
		}
		if ($relativePath === '') {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($relativePath);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('RestoreFromTrash listener could not resolve legacy restore path.', [
				'app' => 'etherpad_nextcloud',
				'filePath' => $path,
				'exception' => $e,
			]);
			return null;
		}

		return $node instanceof File ? $node : null;
	}
}
