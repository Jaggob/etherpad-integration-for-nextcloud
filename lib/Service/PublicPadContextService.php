<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Share\IShare;

/**
 * Assembles all data needed by the public viewer API response for one .pad file.
 *
 * The service keeps the controller out of share/file metadata parsing and leaves
 * final HTTP response shaping to the controller.
 */
class PublicPadContextService {
	public function __construct(
		private PublicShareResolver $shareResolver,
		private PadFileService $padFileService,
		private BindingService $bindingService,
		private PublicPadOpenService $publicPadOpenService,
	) {
	}

	public function resolve(string $token, mixed $fileParam, ?IShare $cachedShare = null): PublicPadContext {
		$share = $this->shareResolver->resolveShare($token, $cachedShare);
		$resolved = $this->shareResolver->resolvePadFile($share, $fileParam, $token);
		$node = $resolved->node;

		$content = (string)$node->getContent();
		$fileId = (int)$node->getId();

		$parsed = $this->padFileService->parsePadFile($content);
		$frontmatter = $parsed['frontmatter'];
		$meta = $this->padFileService->extractPadMetadata($frontmatter);
		$padId = $meta['pad_id'];
		$accessMode = $meta['access_mode'];
		$padUrl = $meta['pad_url'];
		$isExternal = $this->padFileService->isExternalFrontmatter($frontmatter, $padId);

		$this->bindingService->assertConsistentMapping($fileId, $padId, $accessMode);
		$openTarget = $this->publicPadOpenService->open(
			$padId,
			$accessMode,
			$resolved->readOnly,
			$token,
			$isExternal,
			$content,
			$padUrl,
		);

		return new PublicPadContext(
			$resolved->name,
			$openTarget->url,
			$isExternal,
			$openTarget->isReadOnlySnapshot,
			$openTarget->snapshotText,
			$openTarget->snapshotHtml,
			$openTarget->originalPadUrl,
			$openTarget->cookieHeader,
		);
	}
}
