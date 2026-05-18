<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCP\Files\File;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class PadBootstrapService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Seeds a freshly provisioned pad with content. Tries setHTML first so
	 * formatting (headings, lists, bold/italic) survives, and falls back to
	 * plain-text setText if the HTML import fails or no HTML snapshot is
	 * available.
	 *
	 * We intentionally do NOT also call setText after a successful setHTML:
	 * Etherpad's `setText` replaces the pad content rather than appending, so
	 * doing both would wipe the formatted HTML we just imported. The minor
	 * downside — `getText` returns plain text Etherpad derived from the HTML
	 * rather than the exact string we put into the frontmatter snapshot — is
	 * acceptable in exchange for keeping the rich formatting.
	 */
	public function pushInitialSnapshot(string $padId, string $text, string $html): void {
		if (trim($html) !== '') {
			try {
				$this->etherpadClient->setHTML($padId, $html);
				return;
			} catch (\Throwable $htmlError) {
				$this->logger->warning('Initial HTML push failed, falling back to plain text.', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $htmlError,
				]);
			}
		}
		$this->etherpadClient->setText($padId, $text);
	}

	public function provisionPadId(string $accessMode): string {
		if ($accessMode === BindingService::ACCESS_PUBLIC) {
			$padId = 'nc-' . $this->secureRandom->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$this->etherpadClient->createPad($padId);
			return $padId;
		}

		if ($accessMode !== BindingService::ACCESS_PROTECTED) {
			throw new \InvalidArgumentException('Unsupported access mode for pad provisioning.');
		}

		$groupId = $this->etherpadClient->createGroup();
		$padName = $this->buildProtectedPadName();
		return $this->etherpadClient->createGroupPad($groupId, $padName);
	}

	public function initializeMissingFrontmatter(File $file, string $existingContent): void {
		$fileId = (int)$file->getId();
		$existingContentTrimmed = trim($existingContent);
		$isEmptyFile = $existingContentTrimmed === '';
		$legacyShortcut = $this->padFileService->parseLegacyOwnpadShortcut($existingContent);
		if (!$isEmptyFile && $legacyShortcut === null) {
			throw new PadFileFormatException('Missing YAML frontmatter in .pad file.');
		}
		if ($legacyShortcut !== null) {
			throw new PadFileFormatException('Legacy Ownpad .pad files cannot be auto-imported.');
		}

		$binding = $this->bindingService->findByFileId($fileId);
		$createdNewBinding = false;
		$createdNewPad = false;
		$padId = '';
		$accessMode = BindingService::ACCESS_PROTECTED;
		$padUrl = null;

		if ($binding !== null) {
			$padId = (string)$binding['pad_id'];
			$accessMode = (string)$binding['access_mode'];
			if ($legacyShortcut !== null) {
				$padUrl = (string)$legacyShortcut['url'];
			}
		} else {
			$padId = $this->provisionPadId(BindingService::ACCESS_PROTECTED);
			$accessMode = BindingService::ACCESS_PROTECTED;
			$this->bindingService->createBinding($fileId, $padId, $accessMode);
			$createdNewBinding = true;
			$createdNewPad = true;
		}

		try {
			$effectivePadUrl = ($padUrl !== null && $padUrl !== '')
				? $padUrl
				: $this->etherpadClient->buildPadUrl($padId);
			$doc = $this->padFileService->buildInitialDocument($fileId, $padId, $accessMode, '', $effectivePadUrl);
			$file->putContent($doc);
		} catch (\Throwable $e) {
			if ($createdNewBinding) {
				if ($createdNewPad) {
					try {
						$this->etherpadClient->deletePad($padId);
					} catch (\Throwable $cleanupError) {
						$this->logger->warning('Could not cleanup Etherpad pad after frontmatter init failure.', [
							'app' => 'etherpad_nextcloud',
							'fileId' => $fileId,
							'padId' => $padId,
							'exception' => $cleanupError,
						]);
					}
				}
				try {
					$this->bindingService->deleteByFileId($fileId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not cleanup binding after frontmatter init failure.', [
						'app' => 'etherpad_nextcloud',
						'fileId' => $fileId,
						'padId' => $padId,
						'exception' => $cleanupError,
					]);
				}
			}
			throw $e;
		}
	}

	private function buildProtectedPadName(): string {
		return 'p-' . $this->secureRandom->generate(20, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}
}
