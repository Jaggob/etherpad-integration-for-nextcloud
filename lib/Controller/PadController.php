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
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadBootstrapService;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadController extends Controller {
	private const OPEN_LOCK_RETRY_DELAYS_US = [100000, 200000, 400000];
	private const SYNC_LOCK_RETRY_DELAYS_US = [150000, 300000, 600000];

	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private PathNormalizer $pathNormalizer,
		private PadFileService $padFileService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private PadBootstrapService $padBootstrapService,
		private AppConfigService $appConfigService,
		private LifecycleService $lifecycleService,
		private IRootFolder $rootFolder,
		private UserNodeResolver $userNodeResolver,
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
			$path = $this->normalizeCreatePath($file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();

		$padId = '';
		$fileCreated = false;
		try {
			$fileNode = $this->createUserFile($uid, $path);
			$fileCreated = true;
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}
			$padId = $this->padBootstrapService->provisionPadId($accessMode);

			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				'',
				$this->etherpadClient->buildPadUrl($padId)
			);
			$fileNode->putContent($content);

			$this->bindingService->createBinding($fileId, $padId, $accessMode);

			return new DataResponse([
				'file' => $path,
				'file_id' => $fileId,
				'pad_id' => $padId,
				'access_mode' => $accessMode,
				'pad_url' => $this->etherpadClient->buildPadUrl($padId),
				'viewer_url' => $this->buildFilesViewerUrl($fileId, $path),
			]);
		} catch (BindingException $e) {
			$this->logger->warning('Pad create hit existing binding', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'accessMode' => $accessMode,
				'padId' => $padId,
				'exception' => $e,
			]);
			$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($this->isCreateConflict($e)) {
				$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			$this->logger->error('Pad creation failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'accessMode' => $accessMode,
				'padId' => $padId,
				'exception' => $e,
			]);

			$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
			$fileName = $this->normalizeCreateFileName($name);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid pad name.'], Http::STATUS_BAD_REQUEST);
		}

		$uid = $user->getUID();
		try {
			$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($uid, $parentFolderId);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve selected parent folder.'], Http::STATUS_NOT_FOUND);
		}

		if (!$parentFolder->isCreatable()) {
			return new DataResponse(['message' => 'Selected parent folder is not writable.'], Http::STATUS_FORBIDDEN);
		}

		$padId = '';
		$fileCreated = false;
		$path = '';

		try {
			$fileNode = $this->createUserFileInFolder($parentFolder, $fileName);
			$fileCreated = true;
			$path = $this->userNodeResolver->toUserAbsolutePath($uid, $fileNode);
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}

			$padId = $this->padBootstrapService->provisionPadId($accessMode);
			$content = $this->padFileService->buildInitialDocument(
				$fileId,
				$padId,
				$accessMode,
				'',
				$this->etherpadClient->buildPadUrl($padId)
			);
			$fileNode->putContent($content);
			$this->bindingService->createBinding($fileId, $padId, $accessMode);

			return new DataResponse([
				'file' => $path,
				'file_id' => $fileId,
				'parent_folder_id' => $parentFolderId,
				'pad_id' => $padId,
				'access_mode' => $accessMode,
				'pad_url' => $this->etherpadClient->buildPadUrl($padId),
				'viewer_url' => $this->buildFilesViewerUrl($fileId, $path),
				'embed_url' => $this->buildEmbedUrl($fileId),
			]);
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
			$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($this->isCreateConflict($e)) {
				$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			$this->logger->error('Pad creation by parent failed', [
				'app' => 'etherpad_nextcloud',
				'parentFolderId' => $parentFolderId,
				'padName' => $name,
				'path' => $path,
				'accessMode' => $accessMode,
				'padId' => $padId,
				'exception' => $e,
			]);

			$this->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
			$path = $this->normalizeCreatePath($file);
			$external = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
		} catch (EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid input.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();

		$fileCreated = false;
		try {
			$fileNode = $this->createUserFile($uid, $path);
			$fileCreated = true;
			$fileId = (int)$fileNode->getId();
			if ($fileId <= 0) {
				throw new \RuntimeException('Could not resolve new file ID.');
			}

			$bindingPadId = $this->buildExternalBindingPadId($external['origin'], $external['pad_id'], $fileId);
			// Validate that the target behaves like a public Etherpad pad before persisting binding.
			$this->etherpadClient->getPublicTextFromPadUrl($external['pad_url']);
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

			return new DataResponse([
				'file' => $path,
				'file_id' => $fileId,
				'pad_id' => $bindingPadId,
				'access_mode' => BindingService::ACCESS_PUBLIC,
				'pad_url' => $external['pad_url'],
				'viewer_url' => $this->buildFilesViewerUrl($fileId, $path),
			]);
		} catch (BindingException $e) {
			$this->logger->warning('External pad URL already linked', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'origin' => $external['origin'],
				'remotePadId' => $external['pad_id'],
				'exception' => $e,
			]);
			$this->rollbackExternalCreate($uid, $path, $fileCreated);
			return new DataResponse(['message' => 'Could not create external pad binding.'], Http::STATUS_CONFLICT);
		} catch (EtherpadClientException $e) {
			$this->logger->warning('External pad URL validation failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'padUrl' => $padUrl,
				'exception' => $e,
			]);
			$this->rollbackExternalCreate($uid, $path, $fileCreated);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			if ($this->isCreateConflict($e)) {
				$this->rollbackExternalCreate($uid, $path, $fileCreated);
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			$this->logger->error('External pad create failed', [
				'app' => 'etherpad_nextcloud',
				'file' => $path,
				'padUrl' => $padUrl,
				'exception' => $e,
			]);
			$this->rollbackExternalCreate($uid, $path, $fileCreated);
			return new DataResponse(['message' => 'External pad create failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
			$path = $this->pathNormalizer->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->resolveUserPadNode($uid, $path);
			$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
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
			$node = $this->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}

		return $this->openPadInternal($uid, $user->getDisplayName(), $node, $absolutePath);
	}

	private function openPadInternal(string $uid, string $displayName, File $node, string $absolutePath): DataResponse {
		try {
			$content = $this->readContentWithOpenLockRetry($node);
			$fileId = (int)$node->getId();
			if ($fileId <= 0) {
				return new DataResponse(['message' => 'Could not resolve file ID.'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$parsed = $this->padFileService->parsePadFile((string)$content);
			$meta = $this->extractPadMetadata($parsed['frontmatter']);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($meta, $padId);
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			return $this->buildOpenResponse(
				$uid,
				$displayName,
				$absolutePath,
				$fileId,
				$padId,
				$accessMode,
				$padUrl,
				$isExternal
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
			$path = $this->pathNormalizer->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		if ($path === '') {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		}
		$uid = $user->getUID();
		try {
			$node = $this->resolveUserPadNode($uid, $path);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}
		$content = (string)$node->getContent();
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Could not resolve file ID.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$result = $this->initializePadFrontmatter($uid, $node, (string)$content);
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
			$node = $this->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		}
		$content = (string)$node->getContent();

		try {
			$result = $this->initializePadFrontmatter($uid, $node, (string)$content);
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

		$uid = $user->getUID();
		try {
			$node = $this->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve selected .pad file.'], Http::STATUS_NOT_FOUND);
		}

		return $this->buildMetaResponse($node, $absolutePath);
	}

	private function toUserFacingBindingErrorMessage(BindingException $e): string {
		$message = trim($e->getMessage());
		if ($message === 'No binding exists for this file.') {
			return 'This .pad file is not linked to a managed pad. It looks like a copied .pad file. Open the original .pad file or create a new pad.';
		}
		return $message;
	}

	private function buildMetaResponse(File $node, string $absolutePath): DataResponse {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			return new DataResponse(['message' => 'Could not resolve file ID.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$isPad = str_ends_with(strtolower($absolutePath), '.pad');
		if (!$isPad) {
			return new DataResponse([
				'is_pad' => false,
				'file_id' => $fileId,
				'name' => $node->getName(),
				'path' => $absolutePath,
			]);
		}

		$isPadMime = (string)$node->getMimeType() === 'application/x-etherpad-nextcloud';
		$accessMode = '';
		$isExternal = false;
		$publicOpenUrl = '';
		$padUrl = '';
		$padId = '';
		try {
			$parsed = $this->padFileService->parsePadFile((string)$node->getContent());
			$meta = $this->extractPadMetadata($parsed['frontmatter']);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($meta, $padId);

			if ($accessMode === BindingService::ACCESS_PUBLIC) {
				if ($isExternal && $padUrl !== '') {
					$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
					$publicOpenUrl = (string)$normalized['pad_url'];
					$padUrl = $publicOpenUrl;
				} elseif ($padId !== '') {
					$publicOpenUrl = $this->etherpadClient->buildPadUrl($padId);
					$padUrl = $publicOpenUrl;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Pad meta parse skipped', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'exception' => $e,
			]);
		}

		return new DataResponse([
			'is_pad' => true,
			'is_pad_mime' => $isPadMime,
			'file_id' => $fileId,
			'name' => $node->getName(),
			'path' => $absolutePath,
			'access_mode' => $accessMode,
			'is_external' => $isExternal,
			'pad_id' => $padId,
			'pad_url' => $padUrl,
			'public_open_url' => $publicOpenUrl,
			'viewer_url' => $this->buildFilesViewerUrl($fileId, $absolutePath),
			'embed_url' => $this->buildEmbedUrl($fileId),
		]);
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

		$uid = $user->getUID();
		$resolvedFileId = $fileId;
		$normalizedPath = '';
		$mime = '';
		if ($resolvedFileId > 0) {
			try {
				$node = $this->resolveUserPadNodeById($uid, $resolvedFileId);
				$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
				$mime = (string)$node->getMimeType();
			} catch (NotFoundException) {
				return new DataResponse(['is_pad' => false, 'file_id' => $resolvedFileId]);
			}
		} else {
			try {
				$requestedPath = $this->pathNormalizer->normalizeViewerFilePath($file);
			} catch (\Throwable) {
				return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
			}
			if ($requestedPath === '') {
				return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
			}

			try {
				$node = $this->resolveUserPadNode($uid, $requestedPath);
			} catch (NotFoundException) {
				return new DataResponse(['is_pad' => false, 'path' => $requestedPath]);
			}
			$resolvedFileId = (int)$node->getId();
			if ($resolvedFileId <= 0) {
				return new DataResponse(['is_pad' => false, 'path' => $requestedPath]);
			}
			$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
			$mime = (string)$node->getMimeType();
		}

		$isPad = str_ends_with(strtolower($normalizedPath), '.pad');
		if (!$isPad) {
			return new DataResponse(['is_pad' => false, 'file_id' => $resolvedFileId, 'path' => $normalizedPath]);
		}

		$isPadMime = $mime === 'application/x-etherpad-nextcloud';
		$accessMode = '';
		$isExternal = false;
		$publicOpenUrl = '';
		try {
			$parsed = $this->padFileService->parsePadFile((string)$node->getContent());
			$meta = $this->extractPadMetadata($parsed['frontmatter']);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($meta, $padId);

			if ($accessMode === BindingService::ACCESS_PUBLIC) {
				if ($isExternal && $padUrl !== '') {
					$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
					$publicOpenUrl = (string)$normalized['pad_url'];
				} elseif ($padId !== '') {
					$publicOpenUrl = $this->etherpadClient->buildPadUrl($padId);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Pad resolve metadata parse skipped', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $resolvedFileId,
				'path' => $normalizedPath,
				'exception' => $e,
			]);
		}

		return new DataResponse([
			'is_pad' => true,
			'is_pad_mime' => $isPadMime,
			'file_id' => $resolvedFileId,
			'path' => $normalizedPath,
			'access_mode' => $accessMode,
			'is_external' => $isExternal,
			'public_open_url' => $publicOpenUrl,
			'viewer_url' => $this->buildFilesViewerUrl($resolvedFileId, $normalizedPath),
		]);
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
		$uid = $user->getUID();
		$absolutePath = '';
		$padId = '';
		$accessMode = '';
		$isExternal = false;
		try {
			$node = $this->resolveUserPadNodeById($uid, $fileId);
			$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot resolve file path for file ID.'], Http::STATUS_NOT_FOUND);
		}
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			return new DataResponse(['message' => 'Selected file is not a .pad file.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$currentContent = (string)$node->getContent();
			$parsed = $this->padFileService->parsePadFile((string)$currentContent);
			$meta = $this->extractPadMetadata($parsed['frontmatter']);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($meta, $padId);
			$lockRetries = 0;
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);

			if ($isExternal) {
				if ($padUrl === '') {
					return new DataResponse(['message' => 'External pad URL metadata is missing or invalid.'], Http::STATUS_BAD_REQUEST);
				}
				$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);

				// External sync already performs a live upstream text fetch on every call.
				// force=1 therefore does not unlock a cheaper fast path here; it only marks
				// the caller intent while preserving the "no blind rewrite" invariant.
				$text = $this->etherpadClient->getPublicTextFromPadUrl($normalized['pad_url']);

				$existingText = $this->padFileService->getTextSnapshotForRestore((string)$currentContent);
				if ($existingText === $text) {
					return new DataResponse([
						'status' => 'unchanged',
						'file_id' => $fileId,
						'pad_id' => $padId,
						'external' => true,
						'forced' => $force,
					]);
				}

				$previousRev = $this->padFileService->getSnapshotRevision((string)$currentContent);
				$nextRev = max(0, $previousRev + 1);
				$updatedContent = $this->padFileService->withExportSnapshot((string)$currentContent, $text, '', $nextRev, false);
				$this->putContentWithSyncLockRetry($node, $updatedContent, $lockRetries);

				return new DataResponse([
					'status' => 'updated',
					'file_id' => $fileId,
					'pad_id' => $padId,
					'external' => true,
					'forced' => $force,
					'snapshot_rev' => $nextRev,
					'lock_retries' => $lockRetries,
				]);
			}

			$currentRev = $this->etherpadClient->getRevisionsCount($padId);
			$snapshotRev = $this->padFileService->getSnapshotRevision((string)$currentContent);
			if (!$force && $snapshotRev >= $currentRev) {
				return new DataResponse([
					'status' => 'unchanged',
					'file_id' => $fileId,
					'pad_id' => $padId,
					'forced' => false,
					'snapshot_rev' => $snapshotRev,
					'current_rev' => $currentRev,
				]);
			}

			$text = $this->etherpadClient->getText($padId);
			$html = $this->etherpadClient->getHTML($padId);
			if ($force && $snapshotRev >= $currentRev) {
				// force=1 still matters for internal pads: it bypasses the cheap revision
				// short-circuit and performs a live content re-check before deciding that
				// the local snapshot is unchanged.
				$existingText = $this->padFileService->getTextSnapshotForRestore((string)$currentContent);
				$existingHtml = $this->padFileService->getHtmlSnapshotForRestore((string)$currentContent);
				if ($existingText === $text && $existingHtml === $html) {
					return new DataResponse([
						'status' => 'unchanged',
						'file_id' => $fileId,
						'pad_id' => $padId,
						'forced' => true,
						'snapshot_rev' => $snapshotRev,
						'current_rev' => $currentRev,
					]);
				}
			}
			$updatedContent = $this->padFileService->withExportSnapshot((string)$currentContent, $text, $html, $currentRev);
			$this->putContentWithSyncLockRetry($node, $updatedContent, $lockRetries);

			return new DataResponse([
				'status' => 'updated',
				'file_id' => $fileId,
				'pad_id' => $padId,
				'forced' => $force,
				'snapshot_rev' => $currentRev,
				'lock_retries' => $lockRetries,
			]);
		} catch (LockedException $e) {
			$this->logger->warning('Pad sync deferred because .pad file is locked', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'padId' => $padId,
				'accessMode' => $accessMode,
				'external' => $isExternal,
				'force' => $force,
				'lockRetryAttempts' => $lockRetries ?? 0,
				'exception' => $e,
			]);
			return new DataResponse([
				'status' => 'locked',
				'file_id' => $fileId,
				'pad_id' => $padId,
				'external' => $isExternal,
				'forced' => $force,
				'lock_retries' => $lockRetries ?? 0,
				'retryable' => true,
			]);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'path' => $absolutePath,
				'force' => $force,
				'exception' => $e,
			]);
			return new DataResponse(['message' => 'Pad sync failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function putContentWithSyncLockRetry(File $node, string $content, int &$lockRetries): void {
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

	private function readContentWithOpenLockRetry(File $node): string {
		foreach (self::OPEN_LOCK_RETRY_DELAYS_US as $delay) {
			try {
				return (string)$node->getContent();
			} catch (LockedException) {
				\usleep($delay);
			}
		}

		// Final uncaught attempt preserves the original LockedException for the caller
		// once the bounded retry budget has been exhausted.
		return (string)$node->getContent();
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array{pad_id:string,access_mode:string,pad_url:string}
	 */
	private function extractPadMetadata(array $meta): array {
		return [
			'pad_id' => isset($meta['pad_id']) ? (string)$meta['pad_id'] : '',
			'access_mode' => isset($meta['access_mode']) ? (string)$meta['access_mode'] : '',
			'pad_url' => isset($meta['pad_url']) ? trim((string)$meta['pad_url']) : '',
		];
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
		$uid = $user->getUID();
		try {
			$node = $this->resolveUserPadNodeById($uid, $fileId);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot read selected .pad file.'], Http::STATUS_NOT_FOUND);
		}
		$content = (string)$node->getContent();

		try {
			$parsed = $this->padFileService->parsePadFile((string)$content);
			$meta = $this->extractPadMetadata($parsed['frontmatter']);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);

			$isExternal = $this->padFileService->isExternalFrontmatter($meta, $padId);
			if ($isExternal) {
				return new DataResponse([
					'status' => 'unavailable',
					'in_sync' => null,
					'reason' => 'external_no_revision',
				]);
			}

			$currentRev = $this->etherpadClient->getRevisionsCount($padId);
			$snapshotRev = $this->padFileService->getSnapshotRevision((string)$content);
			$inSync = $snapshotRev >= $currentRev;

			return new DataResponse([
				'status' => $inSync ? 'synced' : 'out_of_sync',
				'in_sync' => $inSync,
				'snapshot_rev' => $snapshotRev,
				'current_rev' => $currentRev,
			]);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->toUserFacingBindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad sync status check failed', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'exception' => $e,
			]);
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
			$path = $this->pathNormalizer->normalizeViewerFilePath($file);
			$node = $this->resolveUserPadNode($user->getUID(), $path);
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
			$path = $this->pathNormalizer->normalizeViewerFilePath($file);
			$node = $this->resolveUserPadNode($user->getUID(), $path);
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

	/** @return array{status:string,file:string,file_id:int,pad_id:string,access_mode:string} */
	private function initializePadFrontmatter(string $uid, File $file, string $content): array {
		$fileId = (int)$file->getId();
		$path = $this->userNodeResolver->toUserAbsolutePath($uid, $file);
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

	private function normalizeCreatePath(string $file): string {
		$path = $this->pathNormalizer->normalizeViewerFilePath($file);
		if (!str_ends_with(strtolower($path), '.pad')) {
			$path .= '.pad';
		}
		return $path;
	}

	private function normalizeCreateFileName(string $name): string {
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

	private function rollbackFailedCreate(string $uid, string $path, string $padId, bool $fileCreated): void {
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

	private function rollbackExternalCreate(string $uid, string $path, bool $fileCreated): void {
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

	private function buildExternalBindingPadId(string $origin, string $remotePadId, int $fileId): string {
		return 'ext.' . substr(hash('sha256', $origin . '|' . $remotePadId . '|' . $fileId), 0, 40);
	}

	private function buildOpenResponse(
		string $uid,
		string $displayName,
		string $path,
		int $fileId,
		string $padId,
		string $accessMode,
		string $padUrl = '',
		bool $isExternal = false
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

	/**
	 * @throws NotFoundException
	 */
	private function resolveUserPadNode(string $uid, string $absolutePath): File {
		$relativePath = ltrim($absolutePath, '/');
		if ($relativePath === '') {
			throw new NotFoundException('Invalid empty file path.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		$node = $userFolder->get($relativePath);
		if (!$node instanceof File) {
			throw new NotFoundException('Path does not reference a file.');
		}

		return $node;
	}

	/**
	 * @throws NotFoundException
	 */
	private function resolveUserPadNodeById(string $uid, int $fileId): File {
		return $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
	}

	/**
	 * @throws NotFoundException
	 */
	private function toUserAbsolutePath(string $uid, File $node): string {
		return $this->userNodeResolver->toUserAbsolutePath($uid, $node);
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

	/**
	 * @throws \RuntimeException
	 */
	private function createUserFile(string $uid, string $absolutePath): File {
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
		if (!($parent instanceof \OCP\Files\Folder)) {
			throw new \RuntimeException('Target parent folder does not exist.');
		}

		return $this->createUserFileInFolder($parent, $fileName);
	}

	/**
	 * @throws \RuntimeException
	 */
	private function createUserFileInFolder(Folder $parent, string $fileName): File {
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

	private function isCreateConflict(\Throwable $e): bool {
		return $e->getCode() === Http::STATUS_CONFLICT;
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
