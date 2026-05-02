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
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PublicPadOpenService;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\PublicShareController;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\IShare;

class PublicViewerController extends PublicShareController {
	private ?IShare $share = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private PublicShareResolver $shareResolver,
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
			$this->share = $this->shareResolver->resolveShare($this->getToken());
		} catch (InvalidShareTokenException) {
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
			return new DataResponse(['message' => $e->getMessage()], $this->mapPublicShareStatus($e));
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
		$share = $this->shareResolver->resolveShare($token, $this->share);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token);
		$node = $resolved->node;

		$content = (string)$node->getContent();
		$fileId = (int)$node->getId();

		try {
			$parsed = $this->padFileService->parsePadFile($content);
			$frontmatter = $parsed['frontmatter'];
			$meta = $this->padFileService->extractPadMetadata($frontmatter);
			$padId = $meta['pad_id'];
			$accessMode = $meta['access_mode'];
			$padUrl = $meta['pad_url'];
			$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);

			$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
			$openTarget = $this->publicPadOpenService->open($padId, $accessMode, $resolved->readOnly, $token, $isExternal, $content, $padUrl);
		} catch (PadFileFormatException|BindingException|EtherpadClientException $e) {
			$this->publicFail($this->mapPublicOpenError($e), Http::STATUS_BAD_REQUEST);
		}

		return [
			'title' => $resolved->name,
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
			'download_url' => $this->shareUrlBuilder->buildDownloadUrl($token, $resolved->selectedRelativePath, $resolved->isFolderShare, $resolved->name),
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

	private function mapPublicShareStatus(\RuntimeException $e): int {
		return match (true) {
			$e instanceof InvalidShareTokenException,
			$e instanceof ShareItemUnavailableException,
			$e instanceof ShareFileNotInShareException => Http::STATUS_NOT_FOUND,
			$e instanceof ShareReadForbiddenException => Http::STATUS_FORBIDDEN,
			$e instanceof InvalidShareFilePathException,
			$e instanceof NoShareFileSelectedException,
			$e instanceof NotAPadFileException => Http::STATUS_BAD_REQUEST,
			default => $e->getCode() > 0 ? $e->getCode() : Http::STATUS_BAD_REQUEST,
		};
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
