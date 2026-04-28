<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadFileOperationService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private PadFileService $padFileService,
		private PadFileOperationService $padFileOperations,
		private PadCreationService $padCreationService,
		private PadInitializationService $padInitializationService,
		private PadMetadataService $padMetadataService,
		private PadSyncService $padSyncService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private AppConfigService $appConfigService,
		private LifecycleService $lifecycleService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create a new Etherpad-backed .pad file and binding.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function create(string $file, string $accessMode = BindingService::ACCESS_PROTECTED): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		if (!in_array($accessMode, [BindingService::ACCESS_PUBLIC, BindingService::ACCESS_PROTECTED], true)) {
			return new DataResponse(['message' => 'Invalid accessMode. Use public or protected.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->padCreationService->create($user->getUID(), $file, $accessMode);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		} catch (BindingException $e) {
			return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($this->padFileOperations->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$result['viewer_url'] = $this->buildFilesViewerUrl((int)$result['file_id'], (string)$result['file']);
		return new DataResponse($result);
	}

	/**
	 * Create a new Etherpad-backed .pad file and binding inside an existing parent folder.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createByParent(int $parentFolderId, string $name, string $accessMode = BindingService::ACCESS_PROTECTED): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($parentFolderId <= 0) {
			return new DataResponse(['message' => 'Invalid parentFolderId.'], Http::STATUS_BAD_REQUEST);
		}
		if (!in_array($accessMode, [BindingService::ACCESS_PUBLIC, BindingService::ACCESS_PROTECTED], true)) {
			return new DataResponse(['message' => 'Invalid accessMode. Use public or protected.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->padCreationService->createInParent($user->getUID(), $parentFolderId, $name, $accessMode);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid pad name.'], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve selected parent folder.'], Http::STATUS_NOT_FOUND);
		} catch (BindingException) {
			return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($e->getCode() === Http::STATUS_FORBIDDEN) {
				return new DataResponse(['message' => 'Selected parent folder is not writable.'], Http::STATUS_FORBIDDEN);
			}
			if ($this->padFileOperations->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$result['viewer_url'] = $this->buildFilesViewerUrl((int)$result['file_id'], (string)$result['file']);
		$result['embed_url'] = $this->buildEmbedUrl((int)$result['file_id']);
		return new DataResponse($result);
	}

	/**
	 * Create a new .pad file linked to a public Etherpad URL from any server.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createFromUrl(string $file, string $padUrl): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->padCreationService->createFromUrl($user->getUID(), $file, $padUrl);
		} catch (EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid input.'], Http::STATUS_BAD_REQUEST);
		} catch (BindingException) {
			return new DataResponse(['message' => 'Could not create external pad binding.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($this->padFileOperations->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'External pad create failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$result['viewer_url'] = $this->buildFilesViewerUrl((int)$result['file_id'], (string)$result['file']);
		return new DataResponse($result);
	}

	/**
	 * Resolve an existing .pad file to a secure open URL.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function open(string $file): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->padFileOperations->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->padFileOperations->resolveUserPadNode($uid, $path);
			$absolutePath = $this->padFileOperations->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}

		return $this->openPadInternal($uid, $user->getDisplayName(), $node, $absolutePath);
	}

	/**
	 * Resolve an existing .pad file by file ID to a secure open URL.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function openById(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Invalid file ID.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->padFileOperations->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->padFileOperations->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}

		return $this->openPadInternal($uid, $user->getDisplayName(), $node, $absolutePath);
	}

	private function openPadInternal(string $uid, string $displayName, File $node, string $absolutePath): DataResponse {
		try {
			$content = $this->padFileOperations->readContentWithOpenLockRetry($node);
			$fileId = (int)$node->getId();
			if ($fileId <= 0) {
				return new DataResponse(['message' => 'Could not resolve file ID.'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$parsed = $this->padFileService->parsePadFile((string)$content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);
			$snapshotText = $isExternal ? $this->padFileService->getTextSnapshotForRestore((string)$content) : '';
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			return $this->buildOpenResponse(
				$uid,
				$displayName,
				$absolutePath,
				$fileId,
				$padId,
				$accessMode,
				$padUrl,
				$isExternal,
				$snapshotText
			);
		} catch (LockedException $e) {
			$this->logger->warning('Pad open deferred because .pad file is locked', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$node->getId(),
				'path' => $absolutePath,
				'exception' => $e,
			]);
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initialize(string $file): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->padFileOperations->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		if ($path === '') {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->padFileOperations->resolveUserPadNode($uid, $path);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}
		$content = (string)$node->getContent();
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Could not resolve file ID.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$result = $this->padInitializationService->initialize($uid, $node, (string)$content);
			return new DataResponse($result);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad frontmatter initialization failed in API initialize', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return new DataResponse(['message' => 'Pad initialization failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initializeById(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Invalid file ID.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->padFileOperations->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->padFileOperations->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}
		$content = (string)$node->getContent();

		try {
			$result = $this->padInitializationService->initialize($uid, $node, (string)$content);
			return new DataResponse($result);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad frontmatter initialization failed in API initialize-by-id', [
				'app' => 'etherpad_nextcloud',
				'file' => $absolutePath,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return new DataResponse(['message' => 'Pad initialization failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function metaById(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Invalid file ID.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$data = $this->padMetadataService->metaById($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (LockedException) {
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (($data['is_pad'] ?? false) === true) {
			$fileId = (int)$data['file_id'];
			$path = (string)$data['path'];
			$data['viewer_url'] = $this->buildFilesViewerUrl($fileId, $path);
			$data['embed_url'] = $this->buildEmbedUrl($fileId);
		}

		return new DataResponse($data);
	}

	private function toUserFacingBindingErrorMessage(BindingException $e): string {
		$message = trim($e->getMessage());
		if ($message === 'No binding exists for this file.') {
			return 'This .pad file is not linked to a managed pad. It looks like a copied .pad file. Open the original .pad file or create a new pad.';
		}
		return $message;
	}

	/**
	 * Resolve file id to pad viewer target.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function resolveById(int $fileId = 0, string $file = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->padMetadataService->resolve($user->getUID(), $fileId, $file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}

		if (($data['is_pad'] ?? false) === true) {
			$data['viewer_url'] = $this->buildFilesViewerUrl((int)$data['file_id'], (string)$data['path']);
		}

		return new DataResponse($data);
	}

	/**
	 * Export current Etherpad state into .pad snapshot.
	 * Internal pads: text + html
	 * External pads: text only
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function syncById(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Invalid file ID.'], Http::STATUS_BAD_REQUEST);
		}

		$forceParam = (string)$this->request->getParam('force', '0');
		$force = in_array(strtolower($forceParam), ['1', 'true', 'yes'], true);
		try {
			return new DataResponse($this->padSyncService->syncById($user->getUID(), $fileId, $force));
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve file path for file ID.'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Pad sync failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function syncStatusById(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Invalid file ID.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->padSyncService->syncStatusById($user->getUID(), $fileId));
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot read selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Sync status check failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function trash(string $file): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->padFileOperations->normalizeViewerFilePath($file);
			$node = $this->padFileOperations->resolveUserPadNode($user->getUID(), $path);
			$result = $this->lifecycleService->handleTrash($node);
			if (($result['status'] ?? '') === LifecycleService::RESULT_SKIPPED) {
				return new DataResponse([
					'file' => $path,
					'status' => LifecycleService::RESULT_SKIPPED,
					'reason' => (string)($result['reason'] ?? 'unknown'),
				], Http::STATUS_CONFLICT);
			}
			return new DataResponse([
				'file' => $path,
				'status' => LifecycleService::RESULT_TRASHED,
				'deleted_at' => (int)($result['deleted_at'] ?? 0),
				'snapshot_persisted' => (bool)($result['snapshot_persisted'] ?? false),
				'delete_pending' => (bool)($result['delete_pending'] ?? false),
			]);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Pad file not found.'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Pad trash API failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $file,
				'exception' => $e,
			]);
			return new DataResponse(['message' => 'Trash failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function restore(string $file): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->padFileOperations->normalizeViewerFilePath($file);
			$node = $this->padFileOperations->resolveUserPadNode($user->getUID(), $path);
			$result = $this->lifecycleService->handleRestore($node);
			if (($result['status'] ?? '') === LifecycleService::RESULT_SKIPPED) {
				return new DataResponse([
					'file' => $path,
					'status' => LifecycleService::RESULT_SKIPPED,
					'reason' => (string)($result['reason'] ?? 'unknown'),
				], Http::STATUS_CONFLICT);
			}
			return new DataResponse([
				'file' => $path,
				'status' => LifecycleService::RESULT_RESTORED,
				'old_pad_id' => (string)($result['old_pad_id'] ?? ''),
				'new_pad_id' => (string)($result['new_pad_id'] ?? ''),
			]);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Pad file not found.'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Pad restore API failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $file,
				'exception' => $e,
			]);
			return new DataResponse(['message' => 'Restore failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function buildOpenResponse(
		string $uid,
		string $displayName,
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		string $padUrl = '',
		bool $isExternal = false,
		string $snapshotText = ''
	): DataResponse {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		$effectivePadUrl = '';
		$originalPadUrl = '';

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			$effectivePadUrl = $normalized['pad_url'];
			$originalPadUrl = $normalized['pad_url'];
		} else {
			$effectivePadUrl = $this->etherpadClient->buildPadUrl($padId);
		}

		$cookieHeader = '';
		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			$openContext = $this->padSessionService->createProtectedOpenContext($uid, $displayName, $padId, 3600);
			$url = $openContext['url'];
			$cookieHeader = $this->padSessionService->buildSetCookieHeader($openContext['cookie']);
		} else {
			$url = $effectivePadUrl;
		}

		$response = new DataResponse([
			'file' => $path,
			'file_id' => $fileId,
			'pad_id' => $padId,
			'access_mode' => $accessMode,
			'pad_url' => $effectivePadUrl,
			'is_external' => $isExternal,
			'original_pad_url' => $originalPadUrl,
			'snapshot_text' => $isExternal ? $snapshotText : '',
			'url' => $url,
			'sync_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncById', ['fileId' => $fileId]),
			'sync_status_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncStatusById', ['fileId' => $fileId]),
			'sync_interval_seconds' => $this->appConfigService->getSyncIntervalSeconds(),
		]);
		if ($cookieHeader !== '') {
			$response->addHeader('Set-Cookie', $cookieHeader);
		}
		return $response;
	}

	private function buildFilesViewerUrl(int $fileId, string $absolutePath): string {
		$dir = dirname($absolutePath);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		}
		$base = rtrim($this->urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}

	private function buildEmbedUrl(int $fileId): string {
		return $this->urlGenerator->linkToRoute('etherpad_nextcloud.embed.showById', ['fileId' => $fileId]);
	}

}
