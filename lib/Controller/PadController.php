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
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadLifecycleOperationService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private PadCreateRollbackService $rollbackService,
		private PadCreationService $padCreationService,
		private PadInitializationService $padInitializationService,
		private PadMetadataService $padMetadataService,
		private PadOpenService $padOpenService,
		private PadSyncService $padSyncService,
		private PadLifecycleOperationService $padLifecycleOperations,
		private PadResponseService $padResponses,
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
		} catch (BindingException) {
			return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			if ($this->rollbackService->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($this->padResponses->withViewerUrl($result));
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
			if ($this->rollbackService->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'Pad creation failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($this->padResponses->withViewerAndEmbedUrls($result));
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
			if ($this->rollbackService->isCreateConflict($e)) {
				return new DataResponse(['message' => '.pad file already exists.'], Http::STATUS_CONFLICT);
			}

			return new DataResponse(['message' => 'External pad create failed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($this->padResponses->withViewerUrl($result));
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
			return $this->padResponses->openResponse($this->padOpenService->openByPath($user->getUID(), $user->getDisplayName(), $file));
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (LockedException) {
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
		try {
			return $this->padResponses->openResponse($this->padOpenService->openById($user->getUID(), $user->getDisplayName(), $fileId));
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (LockedException) {
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initialize(string $file): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->padInitializationService->initializeByPath($user->getUID(), $file);
			return new DataResponse($result);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad frontmatter initialization failed in API initialize', [
				'app' => 'etherpad_nextcloud',
				'file' => $file,
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

		try {
			$result = $this->padInitializationService->initializeById($user->getUID(), $fileId);
			return new DataResponse($result);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'Cannot open selected .pad file.'], Http::STATUS_NOT_FOUND);
		} catch (BindingException $e) {
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Pad frontmatter initialization failed in API initialize-by-id', [
				'app' => 'etherpad_nextcloud',
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
			$data = $this->padResponses->withViewerAndEmbedUrls($data);
		}

		return new DataResponse($data);
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
			$data = $this->padResponses->withViewerUrl($data);
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
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
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
			return new DataResponse(['message' => $this->padResponses->bindingErrorMessage($e)], Http::STATUS_BAD_REQUEST);
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
			return $this->padResponses->lifecycleResponse($this->padLifecycleOperations->trashByPath($user->getUID(), $file));
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
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
			return $this->padResponses->lifecycleResponse($this->padLifecycleOperations->restoreByPath($user->getUID(), $file));
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => 'Invalid file path.'], Http::STATUS_BAD_REQUEST);
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

}
