<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\PublicShareController;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

class PublicViewerController extends PublicShareController {
	private ?IShare $share = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private IManager $shareManager,
		private PathNormalizer $pathNormalizer,
		private PadFileService $padFileService,
		private BindingService $bindingService,
		private PublicPadOpenService $publicPadOpenService,
		private PublicShareUrlBuilder $shareUrlBuilder,
		ISession $session,
	) {
		parent::__construct($appName, $request, $session);
	}

	public function isValidToken(): bool {
		try {
			$this->share = $this->shareManager->getShareByToken($this->getToken());
		} catch (ShareNotFound) {
			return false;
		}

		return true;
	}

	protected function isPasswordProtected(): bool {
		return $this->share !== null && $this->share->getPassword() !== null;
	}

	protected function getPasswordHash(): ?string {
		return $this->share?->getPassword();
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPad(string $token): RedirectResponse|TemplateResponse {
		try {
			$target = $this->shareUrlBuilder->buildShareRedirectUrl($token, $this->request->getParam('file', ''));
		} catch (\RuntimeException $e) {
			$status = $e->getCode() > 0 ? $e->getCode() : Http::STATUS_BAD_REQUEST;
			return $this->publicErrorResponse($token, $e->getMessage(), $status);
		}
		return new RedirectResponse($target);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function openPadData(string $token, mixed $file = ''): DataResponse {
		try {
			$context = $this->resolvePublicPadContext($token, $file);
		} catch (\RuntimeException $e) {
			$status = $e->getCode() > 0 ? $e->getCode() : Http::STATUS_BAD_REQUEST;
			return new DataResponse(['message' => $e->getMessage()], $status);
		}

		$response = new DataResponse([
			'title' => $context['title'],
			'url' => $context['url'],
			'is_external' => $context['is_external'],
			'is_readonly_snapshot' => $context['is_readonly_snapshot'],
			'snapshot_text' => $context['snapshot_text'],
			'snapshot_html' => $context['snapshot_html'],
			'original_pad_url' => $context['original_pad_url'],
		]);
		if (($context['cookie_header'] ?? '') !== '') {
			$response->addHeader('Set-Cookie', (string)$context['cookie_header']);
		}
		return $response;
	}

	private function resolvePublicPadContext(string $token, mixed $fileParam): array {
		$share = $this->share;
		if ($share === null) {
			try {
				$share = $this->shareManager->getShareByToken($token);
			} catch (ShareNotFound) {
				$this->publicFail('This share link is invalid or has expired.', Http::STATUS_NOT_FOUND);
			}
		}

		if (!$share instanceof IShare) {
			$this->publicFail('This share link is invalid or has expired.', Http::STATUS_NOT_FOUND);
		}

		if ((((int)$share->getPermissions()) & Constants::PERMISSION_READ) === 0) {
			$this->publicFail('This share link does not allow reading files.', Http::STATUS_FORBIDDEN);
		}

		try {
			$node = $share->getNode();
		} catch (NotFoundException) {
			$this->publicFail('This shared item is no longer available.', Http::STATUS_NOT_FOUND);
		}

		$isFolderShare = $node instanceof Folder;
		$selectedRelativePath = '';

		if ($node instanceof Folder) {
			try {
				$normalized = $this->pathNormalizer->normalizePublicShareFilePath($fileParam, $token);
			} catch (\Throwable) {
				$this->publicFail('Invalid file path.', Http::STATUS_BAD_REQUEST);
			}
			if ($normalized === '') {
				$this->publicFail('No .pad file selected. Open a .pad file from this shared folder.', Http::STATUS_BAD_REQUEST);
			}
			$selectedRelativePath = $normalized;
			try {
				$node = $node->get($normalized);
			} catch (NotFoundException) {
				$this->publicFail('The selected file does not exist in this share.', Http::STATUS_NOT_FOUND);
			}
		}

		if (!$node instanceof File) {
			$this->publicFail('The selected item is not a file.', Http::STATUS_NOT_FOUND);
		}
		if (!str_ends_with(strtolower($node->getName()), '.pad')) {
			$this->publicFail('The selected file is not a .pad document.', Http::STATUS_BAD_REQUEST);
		}

		$content = (string)$node->getContent();
		$fileId = (int)$node->getId();
		$readOnly = (((int)$share->getPermissions()) & Constants::PERMISSION_UPDATE) === 0;

		try {
			$parsed = $this->padFileService->parsePadFile($content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);

			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			$openTarget = $this->publicPadOpenService->open($padId, $accessMode, $readOnly, $token, $isExternal, $content, $padUrl);
		} catch (PadFileFormatException|BindingException|EtherpadClientException $e) {
			$this->publicFail($this->mapPublicOpenError($e), Http::STATUS_BAD_REQUEST);
		}

		return [
			'title' => $node->getName(),
			'url' => $openTarget->url,
			'is_external' => $isExternal,
			'is_readonly_snapshot' => $openTarget->isReadOnlySnapshot,
			'snapshot_text' => $openTarget->snapshotText,
			'snapshot_html' => $openTarget->snapshotHtml,
			'is_public_pad' => $accessMode === BindingService::ACCESS_PUBLIC,
			'open_new_tab_url' => $accessMode === BindingService::ACCESS_PUBLIC ? $openTarget->url : '',
			'original_pad_url' => $openTarget->originalPadUrl,
			'cookie_header' => $openTarget->cookieHeader,
			'files_url' => $this->shareUrlBuilder->buildShareBaseUrl($token),
			'download_url' => $this->shareUrlBuilder->buildDownloadUrl($token, $selectedRelativePath, $isFolderShare, $node->getName()),
		];
	}

	private function publicFail(string $message, int $status): never {
		throw new \RuntimeException($message, $status);
	}

	private function publicErrorResponse(string $token, string $error, int $status = Http::STATUS_BAD_REQUEST): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'noviewer', [
			'error' => $error,
			'back_url' => $this->shareUrlBuilder->buildShareBaseUrl($token),
			'back_label' => 'Back to shared files',
		], 'blank');
		$response->setStatus($status);
		return $response;
	}

	private function mapPublicOpenError(\Throwable $error): string {
		$message = $error->getMessage();
		if ($error instanceof PadFileFormatException) {
			if (str_contains($message, 'Missing YAML frontmatter')) {
				return 'The selected .pad file is missing required metadata.';
			}
			return 'The selected .pad file has an invalid format.';
		}
		if ($error instanceof BindingException) {
			if ($error instanceof MissingBindingException) {
				return 'The selected .pad file is a copied file without an active pad binding. Please open the original shared .pad file.';
			}
			return 'Pad binding is inconsistent. Please contact the share owner.';
		}
		if ($error instanceof EtherpadClientException) {
			return 'Etherpad is currently unavailable for this shared pad.';
		}
		return 'Unable to open pad.';
	}

}
