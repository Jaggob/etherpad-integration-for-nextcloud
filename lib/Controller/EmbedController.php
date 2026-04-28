<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class EmbedController extends Controller {
	/** @var array{requesttoken:string,trusted_embed_origins:list<string>}|null */
	private ?array $embedBaseData = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private CsrfTokenManager $csrfTokenManager,
		private AppConfigService $appConfigService,
		private UserNodeResolver $userNodeResolver,
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
			$fileNode = $this->userNodeResolver->resolveUserFileNodeById($user->getUID(), $id);
		} catch (NotFoundException) {
			return $this->errorResponse('Cannot open selected .pad file.');
		}

		if (!str_ends_with(strtolower($fileNode->getName()), '.pad')) {
			return $this->errorResponse('Selected file is not a .pad file.');
		}

		return $this->buildEmbedTemplateResponse('embed', [
			'file_id' => $id,
			'open_by_id_url' => $this->urlGenerator->linkToRoute($this->appName . '.pad.openById'),
			'initialize_by_id_url_template' => $this->urlGenerator->linkToRoute(
				$this->appName . '.pad.initializeById',
				['fileId' => '__FILE_ID__']
			),
			'l10n' => [
				'loading' => $this->l10n->t('Loading pad...'),
				'error_title' => $this->l10n->t('Unable to open pad'),
				'external_title' => $this->l10n->t('Pad from another server'),
				'external_message' => $this->l10n->t('This view shows the last synced snapshot stored in the .pad file. It is read-only here. To edit the pad, open the original pad in a new tab.'),
				'external_empty' => $this->l10n->t('No synced snapshot is stored in this .pad file yet.'),
				'external_link' => $this->l10n->t('Open original pad in new tab'),
			],
		]);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function createByParent(mixed $parentFolderId): TemplateResponse {
		$createErrorTitle = $this->l10n->t('Unable to create pad');
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse('Authentication required.', $createErrorTitle);
		}
		if (!is_numeric($parentFolderId)) {
			return $this->errorResponse('Invalid parent folder ID.', $createErrorTitle);
		}

		$id = (int)$parentFolderId;
		if ($id <= 0) {
			return $this->errorResponse('Invalid parent folder ID.', $createErrorTitle);
		}

		try {
			$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($user->getUID(), $id);
		} catch (NotFoundException) {
			return $this->errorResponse('Cannot resolve selected parent folder.', $createErrorTitle);
		}

		if (!$parentFolder->isCreatable()) {
			return $this->errorResponse('Selected parent folder is not writable.', $createErrorTitle);
		}

		return $this->buildEmbedTemplateResponse('embed-create', [
			'parent_folder_id' => $id,
			'create_by_parent_url' => $this->urlGenerator->linkToRoute($this->appName . '.pad.createByParent'),
			'l10n' => [
				'loading' => $this->l10n->t('Creating pad...'),
				'error_title' => $this->l10n->t('Unable to create pad'),
				'missing_name' => $this->l10n->t('Pad name is required.'),
				'invalid_access_mode' => $this->l10n->t('Invalid access mode.'),
				'incomplete_config' => $this->l10n->t('Embed configuration is incomplete.'),
			],
		]);
	}

	private function errorResponse(string $error, ?string $title = null): TemplateResponse {
		return $this->buildEmbedTemplateResponse('noviewer', [
			'error' => $error,
			'title' => $title ?? $this->l10n->t('Unable to open pad'),
		]);
	}

	/** @param array<string,mixed> $data */
	private function buildEmbedTemplateResponse(string $template, array $data): TemplateResponse {
		$response = new TemplateResponse(
			$this->appName,
			$template,
			array_merge($this->getEmbedBaseData(), $data),
			'blank'
		);

		return $this->applyEmbedPolicy($response);
	}

	/** @return array{requesttoken:string,trusted_embed_origins:list<string>} */
	private function getEmbedBaseData(): array {
		if ($this->embedBaseData !== null) {
			return $this->embedBaseData;
		}

		$this->embedBaseData = [
			// Intentional use of the internal manager:
			// blank embed templates do not get the normal Nextcloud layout bootstrap,
			// so OC.requestToken is not auto-injected there. In this NC version there is
			// no public OCP CSRF-token service for this use-case, so the encrypted token
			// has to be passed manually into the blank template.
			'requesttoken' => $this->csrfTokenManager->getToken()->getEncryptedValue(),
			'trusted_embed_origins' => $this->appConfigService->getTrustedEmbedOrigins(),
		];

		return $this->embedBaseData;
	}

	private function applyEmbedPolicy(TemplateResponse $response): TemplateResponse {
		$policy = new ContentSecurityPolicy();
		foreach ($this->getEmbedBaseData()['trusted_embed_origins'] as $origin) {
			$policy->addAllowedFrameAncestorDomain($origin);
		}
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
