<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class EmbedController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private AppConfigService $appConfigService,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showById(mixed $fileId): TemplateResponse {
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
			$fileNode = $this->resolveUserFileNodeById($user->getUID(), $id);
		} catch (NotFoundException) {
			return $this->errorResponse('Cannot open selected .pad file.');
		}

		if (!str_ends_with(strtolower($fileNode->getName()), '.pad')) {
			return $this->errorResponse('Selected file is not a .pad file.');
		}

		Util::addScript($this->appName, 'embed-main');
		Util::addStyle($this->appName, 'embed');

		$response = new TemplateResponse($this->appName, 'embed', [
			'file_id' => $id,
			'open_by_id_url' => $this->urlGenerator->linkToRoute($this->appName . '.pad.openById'),
			'initialize_by_id_url_template' => $this->urlGenerator->linkToRoute(
				$this->appName . '.pad.initializeById',
				['fileId' => '__FILE_ID__']
			),
			'l10n' => [
				'loading' => $this->l10n->t('Loading pad...'),
				'error_title' => $this->l10n->t('Unable to open pad'),
			],
		], 'blank');

		$policy = new ContentSecurityPolicy();
		foreach ($this->appConfigService->getTrustedEmbedOrigins() as $origin) {
			$policy->addAllowedFrameAncestorDomain($origin);
		}
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	private function errorResponse(string $error): TemplateResponse {
		return new TemplateResponse($this->appName, 'noviewer', ['error' => $error], 'blank');
	}

	/**
	 * @throws NotFoundException
	 */
	private function resolveUserFileNodeById(string $uid, int $fileId): File {
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
}
