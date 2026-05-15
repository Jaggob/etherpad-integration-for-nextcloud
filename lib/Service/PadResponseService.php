<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IURLGenerator;

class PadResponseService {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private AppConfigService $appConfigService,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function withViewerUrl(array $data): array {
		$data['viewer_url'] = $this->buildFilesViewerUrl((int)$data['file_id'], (string)($data['file'] ?? $data['path']));
		return $data;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function withViewerAndEmbedUrls(array $data): array {
		$data = $this->withViewerUrl($data);
		$data['embed_url'] = $this->buildEmbedUrl((int)$data['file_id']);
		return $data;
	}

	/** @param array<string,mixed> $data */
	public function lifecycleResponse(array $data): DataResponse {
		$status = ($data['status'] ?? '') === LifecycleService::RESULT_SKIPPED
			? Http::STATUS_CONFLICT
			: Http::STATUS_OK;
		return new DataResponse($data, $status);
	}

	public function openResponse(PadOpenTarget $target): DataResponse {
		$payload = [
			'file' => $target->file,
			'file_id' => $target->fileId,
			'pad_id' => $target->padId,
			'access_mode' => $target->accessMode,
			'pad_url' => $target->padUrl,
			'is_external' => $target->isExternal,
			'original_pad_url' => $target->originalPadUrl,
			'snapshot_text' => $target->snapshotText,
			'snapshot_html' => $target->snapshotHtml,
			'url' => $target->url,
			'sync_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncById', ['fileId' => $target->fileId]),
			'sync_status_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncStatusById', ['fileId' => $target->fileId]),
			'sync_interval_seconds' => $this->appConfigService->getSyncIntervalSeconds(),
		];

		$response = new DataResponse($payload);
		if ($target->cookieHeader !== '') {
			$response->addHeader('Set-Cookie', $target->cookieHeader);
		}
		return $response;
	}

	public function bindingErrorMessage(BindingException $e): string {
		$message = trim($e->getMessage());
		if ($e instanceof MissingBindingException) {
			return $this->l10n->t('This .pad file has no matching pad in this Nextcloud.');
		}
		return $message;
	}

	private function buildFilesViewerUrl(int $fileId, string $absolutePath): string {
		$dir = dirname($absolutePath);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		}
		// `files.view.index` resolves to '/apps/files'; the canonical URL
		// the Files app routes to a specific file is
		// `/apps/files/{view}/{fileid}` with `files` as the default view.
		$base = rtrim($this->urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/files/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}

	private function buildEmbedUrl(int $fileId): string {
		return $this->urlGenerator->linkToRoute('etherpad_nextcloud.embed.showById', ['fileId' => $fileId]);
	}
}
