<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;

class PublicPadOpenService {
	private const PUBLIC_SHARE_AUTHOR_NAME = 'Public share';
	private const PUBLIC_SHARE_SESSION_TTL_SECONDS = 3600;

	public function __construct(
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private PadSessionService $padSessionService,
		private SnapshotHtmlSanitizer $snapshotHtmlSanitizer,
	) {
	}

	public function open(
		string $padId,
		string $accessMode,
		bool $readOnly,
		string $token,
		bool $isExternal,
		string $padFileContent,
		string $padUrl = '',
	): PublicPadOpenTarget {
		if ($isExternal && $accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new EtherpadClientException('External pad metadata requires public access_mode.');
		}

		if ($accessMode === BindingService::ACCESS_PROTECTED) {
			if ($readOnly) {
				return new PublicPadOpenTarget(
					'',
					'',
					'',
					true,
					$this->padFileService->getTextSnapshotForRestore($padFileContent),
					$this->snapshotHtmlSanitizer->sanitize($this->padFileService->getHtmlSnapshotForRestore($padFileContent)),
				);
			}

			$openContext = $this->padSessionService->createProtectedOpenContext(
				'public-share:' . $token,
				self::PUBLIC_SHARE_AUTHOR_NAME,
				$padId,
				self::PUBLIC_SHARE_SESSION_TTL_SECONDS
			);

			return new PublicPadOpenTarget(
				$openContext['url'],
				'',
				$this->padSessionService->buildSetCookieHeader($openContext['cookie']),
				false,
				'',
				'',
			);
		}

		if ($isExternal) {
			if ($padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}
			$normalized = $this->etherpadClient->normalizeAndValidateExternalPublicPadUrl($padUrl);
			return new PublicPadOpenTarget(
				$normalized['pad_url'],
				$normalized['pad_url'],
				'',
				false,
				$this->padFileService->getTextSnapshotForRestore($padFileContent),
				'',
			);
		}

		if ($readOnly) {
			return new PublicPadOpenTarget(
				$this->etherpadClient->getReadOnlyPadUrl($padId),
				'',
				'',
				false,
				'',
				'',
			);
		}

		return new PublicPadOpenTarget(
			$this->etherpadClient->buildPadUrl($padId),
			'',
			'',
			false,
			'',
			'',
		);
	}
}
