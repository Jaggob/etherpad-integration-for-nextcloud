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
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSessionService;
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
use OCP\IURLGenerator;
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
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private IURLGenerator $urlGenerator,
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
			$target = $this->buildPublicShareRedirectUrl($token, $this->request->getParam('file', ''));
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
			$openTarget = $this->resolvePublicOpenTarget($padId, $accessMode, $readOnly, $token, $isExternal, $content, $padUrl);
		} catch (PadFileFormatException|BindingException|EtherpadClientException $e) {
			$this->publicFail($this->mapPublicOpenError($e), Http::STATUS_BAD_REQUEST);
		}

		return [
			'title' => $node->getName(),
			'url' => $openTarget['url'],
			'is_external' => $isExternal,
			'is_readonly_snapshot' => $openTarget['is_readonly_snapshot'],
			'snapshot_text' => $openTarget['snapshot_text'],
			'snapshot_html' => $openTarget['snapshot_html'],
			'is_public_pad' => $accessMode === BindingService::ACCESS_PUBLIC,
			'open_new_tab_url' => $accessMode === BindingService::ACCESS_PUBLIC ? $openTarget['url'] : '',
			'original_pad_url' => $openTarget['original_pad_url'],
			'cookie_header' => $openTarget['cookie_header'],
			'files_url' => $this->buildShareBaseUrl($token),
			'download_url' => $this->buildPublicDownloadUrl($token, $selectedRelativePath, $isFolderShare, $node->getName()),
		];
	}

	private function publicFail(string $message, int $status): never {
		throw new \RuntimeException($message, $status);
	}

	private function publicErrorResponse(string $token, string $error, int $status = Http::STATUS_BAD_REQUEST): TemplateResponse {
		$response = new TemplateResponse($this->appName, 'noviewer', [
			'error' => $error,
			'back_url' => $this->buildShareBaseUrl($token),
			'back_label' => 'Back to shared files',
		], 'blank');
		$response->setStatus($status);
		return $response;
	}

	private function buildShareBaseUrl(string $token): string {
		$webroot = rtrim($this->urlGenerator->getWebroot(), '/');
		return $webroot . '/s/' . rawurlencode($token);
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
			if (trim($message) === 'No binding exists for this file.') {
				return 'The selected .pad file is a copied file without an active pad binding. Please open the original shared .pad file.';
			}
			return 'Pad binding is inconsistent. Please contact the share owner.';
		}
		if ($error instanceof EtherpadClientException) {
			return 'Etherpad is currently unavailable for this shared pad.';
		}
		return 'Unable to open pad.';
	}

	private function buildPublicShareRedirectUrl(string $token, mixed $fileParam): string {
		$base = $this->buildShareBaseUrl($token);
		$rawFile = is_scalar($fileParam) ? trim((string)$fileParam) : '';
		if ($rawFile === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		try {
			$normalized = $this->pathNormalizer->normalizePublicShareFilePath($fileParam, $token);
		} catch (\Throwable) {
			$this->publicFail('Invalid file path.', Http::STATUS_BAD_REQUEST);
		}
		if ($normalized === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$path = trim($normalized, '/');
		if ($path === '') {
			return $base . '?dir=' . rawurlencode('/');
		}

		$dir = dirname($path);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$fileName = basename($path);
		if ($fileName === '' || !str_ends_with(strtolower($fileName), '.pad')) {
			$this->publicFail('The selected file is not a .pad document.', Http::STATUS_BAD_REQUEST);
		}

		return $base . '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($fileName);
	}

	private function buildPublicDownloadUrl(string $token, string $selectedRelativePath, bool $isFolderShare, string $fileName): string {
		$base = $this->buildShareBaseUrl($token) . '/download';
		if (!$isFolderShare) {
			return $base;
		}

		$path = trim($selectedRelativePath, '/');
		if ($path === '') {
			return '';
		}

		$dir = dirname($path);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		} else {
			$dir = '/' . $dir;
		}
		$name = basename($path);
		if ($name === '') {
			$name = $fileName;
		}
		if ($name === '') {
			return '';
		}

		return $base . '?path=' . rawurlencode($dir) . '&files=' . rawurlencode($name);
	}

	/** @return array{url:string,original_pad_url:string,cookie_header:string,is_readonly_snapshot:bool,snapshot_text:string,snapshot_html:string} */
	private function resolvePublicOpenTarget(
		string $padId,
		string $accessMode,
		bool $readOnly,
		string $token,
		bool $isExternal,
		string $padFileContent,
		string $padUrl = ''
	): array {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			if ($readOnly) {
				return [
					'url' => '',
					'original_pad_url' => '',
					'cookie_header' => '',
					'is_readonly_snapshot' => true,
					'snapshot_text' => $this->padFileService->getTextSnapshotForRestore($padFileContent),
					'snapshot_html' => $this->sanitizeSnapshotHtml($this->padFileService->getHtmlSnapshotForRestore($padFileContent)),
				];
			}

			$authorUid = 'public-share:' . $token;
			$authorName = 'Public share';
			$openContext = $this->padSessionService->createProtectedOpenContext($authorUid, $authorName, $padId, 3600);
			$cookieHeader = $this->padSessionService->buildSetCookieHeader($openContext['cookie']);
			return [
				'url' => $openContext['url'],
				'original_pad_url' => '',
				'cookie_header' => $cookieHeader,
				'is_readonly_snapshot' => false,
				'snapshot_text' => '',
				'snapshot_html' => '',
			];
		}

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			return [
				'url' => $normalized['pad_url'],
				'original_pad_url' => $normalized['pad_url'],
				'cookie_header' => '',
				'is_readonly_snapshot' => false,
				'snapshot_text' => $this->padFileService->getTextSnapshotForRestore($padFileContent),
				'snapshot_html' => '',
			];
		}

		if ($readOnly) {
			return [
				'url' => $this->etherpadClient->getReadOnlyPadUrl($padId),
				'original_pad_url' => '',
				'cookie_header' => '',
				'is_readonly_snapshot' => false,
				'snapshot_text' => '',
				'snapshot_html' => '',
			];
		}

		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'original_pad_url' => '',
			'cookie_header' => '',
			'is_readonly_snapshot' => false,
			'snapshot_text' => '',
			'snapshot_html' => '',
		];
	}

	private function sanitizeSnapshotHtml(string $html): string {
		$trimmed = trim($html);
		if ($trimmed === '') {
			return '';
		}

		$previous = libxml_use_internal_errors(true);
		$document = new \DOMDocument();
		$loaded = $document->loadHTML(
			'<?xml encoding="UTF-8">' . $trimmed,
			LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return '';
		}

		$body = $document->getElementsByTagName('body')->item(0);
		$root = $body instanceof \DOMNode ? $body : $document;
		$output = '';
		foreach ($root->childNodes as $child) {
			$output .= $this->sanitizeSnapshotHtmlNode($child);
		}
		return trim($output);
	}

	private function sanitizeSnapshotHtmlNode(\DOMNode $node): string {
		if ($node instanceof \DOMText || $node instanceof \DOMCdataSection) {
			return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		if (!$node instanceof \DOMElement) {
			return '';
		}

		$tag = strtolower($node->tagName);
		if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'img', 'video', 'audio', 'source', 'link', 'meta'], true)) {
			return '';
		}

		$content = '';
		foreach ($node->childNodes as $child) {
			$content .= $this->sanitizeSnapshotHtmlNode($child);
		}

		$allowed = ['p', 'br', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'blockquote', 'pre', 'code'];
		if (!in_array($tag, $allowed, true)) {
			return $content;
		}
		if ($tag === 'br') {
			return '<br>';
		}
		return '<' . $tag . '>' . $content . '</' . $tag . '>';
	}

}
