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
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use Psr\Log\LoggerInterface;

class PadCreationService {
	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
		private PadFileCreator $padFileCreator,
		private UserNodeResolver $userNodeResolver,
		private PadCreateRollbackService $rollbackService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadBootstrapService $padBootstrapService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function create(string $uid, string $file, string $accessMode): array {
		$path = $this->padPaths->normalizeCreatePath($file);
		$padId = '';
		$fileCreated = false;

		try {
			$fileNode = $this->padFileCreator->createUserFile($uid, $path);
			$fileCreated = true;
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}
			$padId = $this->padBootstrapService->provisionPadId($accessMode);
			$padUrl = $this->etherpadClient->buildPadUrl($padId);

			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				'',
				$padUrl
			);
			$fileNode->putContent($content);

			$this->bindingService->createBinding($fileId, $padId, $accessMode);

			return [
				'file' => $path,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => $accessMode,
				'pad_url' => $padUrl,
			];
		} catch (BindingException $e) {
			$this->logger->warning('Pad create hit existing binding', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'accessMode' => $accessMode,
				'padId' => $padId,
				'exception' => $e,
			]);
			$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			throw $e;
		} catch (\Throwable $e) {
			if (!($e instanceof PadFileAlreadyExistsException)) {
				$this->logger->error('Pad creation failed', [
					'app' => 'etherpad_nextcloud',
					'file' => $path,
					'accessMode' => $accessMode,
					'padId' => $padId,
					'exception' => $e,
				]);
			}
			$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			throw $e;
		}
	}

	/**
	 * @return array{file:string,file_id:int,parent_folder_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createInParent(string $uid, int $parentFolderId, string $name, string $accessMode): array {
		$fileName = $this->padPaths->normalizeCreateFileName($name);
		$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($uid, $parentFolderId);
		if (!$parentFolder->isCreatable()) {
			throw new PadParentFolderNotWritableException('Selected parent folder is not writable.');
		}

		$padId = '';
		$fileCreated = false;
		$path = '';

		try {
			$fileNode = $this->padFileCreator->createUserFileInFolder($parentFolder, $fileName);
			$fileCreated = true;
			$path = $this->userNodeResolver->toUserAbsolutePath($uid, $fileNode);
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}

			$padId = $this->padBootstrapService->provisionPadId($accessMode);
			$padUrl = $this->etherpadClient->buildPadUrl($padId);
			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				'',
				$padUrl
			);
			$fileNode->putContent($content);
			$this->bindingService->createBinding($fileId, $padId, $accessMode);

			return [
				'file' => $path,
				'file_id' => $fileId,
				'parent_folder_id' => $parentFolderId,
				'pad_id' => $padId,
				'access_mode' => $accessMode,
				'pad_url' => $padUrl,
			];
		} catch (BindingException $e) {
			$this->logger->warning('Pad creation by parent hit existing binding', [
				'app' => 'etherpad_nextcloud',
				'parentFolderId' => $parentFolderId,
				'padName' => $name,
				'path' => $path,
				'accessMode' => $accessMode,
				'padId' => $padId,
				'exception' => $e,
			]);
			$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			throw $e;
		} catch (\Throwable $e) {
			if (!($e instanceof PadFileAlreadyExistsException)) {
				$this->logger->error('Pad creation by parent failed', [
					'app' => 'etherpad_nextcloud',
					'parentFolderId' => $parentFolderId,
					'padName' => $name,
					'path' => $path,
					'accessMode' => $accessMode,
					'padId' => $padId,
					'exception' => $e,
				]);
			}
			$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			throw $e;
		}
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createFromUrl(string $uid, string $file, string $padUrl): array {
		$path = $this->padPaths->normalizeCreatePath($file);
		$external = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
		$fileCreated = false;

		try {
			$fileNode = $this->padFileCreator->createUserFile($uid, $path);
			$fileCreated = true;
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}

			$bindingPadId = $this->rollbackService->buildExternalBindingPadId($external['origin'], $external['pad_id'], $fileId);
			$this->etherpadClient->assertPublicPadAvailable($external['pad_url']);
			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$bindingPadId,
				BindingService::ACCESS_PUBLIC,
				'',
				$external['pad_url'],
				[
					'pad_origin' => $external['origin'],
					'remote_pad_id' => $external['pad_id'],
				]
			);
			$fileNode->putContent($content);

			$this->bindingService->createBinding($fileId, $bindingPadId, BindingService::ACCESS_PUBLIC);

			return [
				'file' => $path,
				'file_id' => $fileId,
				'pad_id' => $bindingPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => $external['pad_url'],
			];
		} catch (BindingException $e) {
			$this->logger->warning('External pad URL already linked', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'origin' => $external['origin'],
				'remotePadId' => $external['pad_id'],
				'exception' => $e,
			]);
			$this->rollbackService->rollbackExternalCreate($uid, $path, $fileCreated);
			throw $e;
		} catch (EtherpadClientException $e) {
			$this->logger->warning('External pad URL validation failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'padUrl' => $padUrl,
				'exception' => $e,
			]);
			$this->rollbackService->rollbackExternalCreate($uid, $path, $fileCreated);
			throw $e;
		} catch (\Throwable $e) {
			if (!($e instanceof PadFileAlreadyExistsException)) {
				$this->logger->error('External pad create failed', [
					'app' => 'etherpad_nextcloud',
					'file' => $path,
					'padUrl' => $padUrl,
					'exception' => $e,
				]);
			}
			$this->rollbackService->rollbackExternalCreate($uid, $path, $fileCreated);
			throw $e;
		}
	}
}
