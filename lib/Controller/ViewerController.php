<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class ViewerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private PathNormalizer $pathNormalizer,
		private UserNodeResolver $userNodeResolver,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPad(mixed $file = ''): TemplateResponse|RedirectResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse('Authentication required.');
		}

		try {
			$normalizedFile = $this->pathNormalizer->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			return $this->errorResponse('Invalid file path.');
		}
		if ($normalizedFile === '') {
			return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index'));
		}

		try {
			$fileNode = $this->resolveUserFileNode($user->getUID(), $normalizedFile);
		} catch (NotFoundException) {
			return $this->errorResponse('Cannot open selected .pad file.');
		}

		return new RedirectResponse($this->buildFilesOpenUrl((int)$fileNode->getId(), $normalizedFile));
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPadById(mixed $fileId): TemplateResponse|RedirectResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse('Authentication required.');
		}
		if (!is_numeric($fileId)) {
			return $this->errorResponse('Invalid file ID.');
		}

		$id = (int)$fileId;
		if ($id <= 0) {
			return $this->errorResponse('Invalid file ID.');
		}

		try {
			$fileNode = $this->userNodeResolver->resolveUserFileNodeById($user->getUID(), $id);
			$path = $this->userNodeResolver->toUserAbsolutePath($user->getUID(), $fileNode);
		} catch (NotFoundException) {
			return $this->errorResponse('Cannot resolve file path for file ID.');
		}

		return new RedirectResponse($this->buildFilesOpenUrl($id, $path));
	}

	private function errorResponse(string $error): TemplateResponse {
		return new TemplateResponse($this->appName, 'noviewer', ['error' => $error], 'blank');
	}

	private function buildFilesOpenUrl(int $fileId, string $absoluteFilePath): string {
		$dir = dirname($absoluteFilePath);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		}
		$base = rtrim($this->urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}

	/**
	 * @throws NotFoundException
	 */
	private function resolveUserFileNode(string $uid, string $absolutePath): File {
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

}
