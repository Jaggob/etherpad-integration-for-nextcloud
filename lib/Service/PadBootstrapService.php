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
	 * Seeds a freshly provisioned pad with content. Imports the HTML snapshot
	 * (so formatting survives) and, when that succeeded, also pushes the
	 * resolved plain-text via setText so Etherpad's `getText` returns the same
	 * string we recorded as the file's text snapshot — otherwise Etherpad
	 * derives plain text from the HTML, which can drift from what the `.pad`
	 * frontmatter claims. When HTML import fails or no HTML snapshot is
	 * provided, setText alone seeds the pad.
	 */
	public function pushInitialSnapshot(string $padId, string $text, string $html): void {
		$htmlSucceeded = false;
		if (trim($html) !== '') {
			try {
				$this->etherpadClient->setHTML($padId, $html);
				$htmlSucceeded = true;
			} catch (\Throwable $htmlError) {
				$this->logger->warning('Initial HTML push failed, falling back to plain text.', [
					'app' => 'etherpad_nextcloud',
					'padId' => $padId,
					'exception' => $htmlError,
				]);
			}
		}

		if ($htmlSucceeded && trim($text) === '') {
			// HTML carried the content and the text snapshot is empty — nothing
			// to add on top.
			return;
		}

		try {
			$this->etherpadClient->setText($padId, $text);
		} catch (\Throwable $textError) {
			if (!$htmlSucceeded) {
				throw $textError;
			}
			// HTML already seeded the pad — log the divergence but don't fail.
			$this->logger->warning('Initial setText after setHTML failed; pad text may diverge from .pad snapshot.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
				'exception' => $textError,
			]);
		}
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
