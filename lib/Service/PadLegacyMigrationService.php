<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Lazy per-file migration of legacy Ownpad `.pad` files (those holding an
 * `[InternetShortcut]` block instead of YAML frontmatter) into the current
 * binding-and-`.pad` model. Called from `PadBootstrapService` when the
 * detection in `PadFileService::parseLegacyOwnpadShortcut` matches.
 *
 * Three branches, all silent in the happy path:
 *
 * 1. **Cross-origin** — the source URL points at a different Etherpad
 *    server than the one we manage. We can't apply protected-mode auth to
 *    a server we don't control, so we route through the existing external
 *    pad shape: write `ext.*` frontmatter, no binding row. Public-only.
 *
 * 2. **Same-origin, no collision** — the source pad-id is not yet bound
 *    to any NC file. We write fresh YAML frontmatter referencing the
 *    same pad-id and create a binding row. The access mode is derived
 *    from the pad-id format (g.X$Y → protected, anything else → public).
 *
 * 3. **Same-origin, collision** — another NC file already owns the
 *    binding for this pad-id. If the requesting user can read that
 *    original file we write frontmatter only (no new binding) and the
 *    file behaves like a copy-of-a-pad; the existing copy-handling in
 *    the open flow takes over. If the user has no access we throw
 *    `LegacyPadCollisionException` and the `.pad` file is left
 *    untouched — subsequent opens see the same legacy state.
 */
class PadLegacyMigrationService {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private PadCreationService $padCreationService,
		private UserNodeResolver $userNodeResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Migrate the legacy `.pad` file in place.
	 *
	 * @param array{url:string,pad_id:string} $legacyShortcut output of
	 *   `PadFileService::parseLegacyOwnpadShortcut`
	 *
	 * @throws LegacyPadCollisionException when the pad is already bound to
	 *   a file the requesting user has no access to.
	 */
	public function migrate(string $uid, File $file, array $legacyShortcut): void {
		$fileId = (int)$file->getId();
		$sourceUrl = $legacyShortcut['url'];
		$sourcePadId = $legacyShortcut['pad_id'];

		$configuredOrigin = $this->etherpadClient->getConfiguredOrigin();
		$sourceOrigin = $this->etherpadClient->normalizeOrigin($sourceUrl);
		$isSameOrigin = $configuredOrigin !== '' && $configuredOrigin === $sourceOrigin;

		if (!$isSameOrigin) {
			$this->padCreationService->seedExternalIntoFile($file, $fileId, $sourceUrl);
			$this->logger->info('Migrated legacy Ownpad .pad as external public pad.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'originBranch' => 'cross',
				'uid' => $uid,
			]);
			return;
		}

		$accessMode = $this->padFileService->inferAccessModeFromPadId($sourcePadId);
		$existingBinding = $this->bindingService->findByPadId($sourcePadId, BindingService::STATE_ACTIVE);

		if ($existingBinding === null) {
			$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
			$this->bindingService->createBinding($fileId, $sourcePadId, $accessMode);
			$this->logger->info('Migrated legacy Ownpad .pad as managed pad (re-bind).', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'originBranch' => 'same',
				'accessMode' => $accessMode,
				'padId' => $sourcePadId,
				'collision' => 'none',
				'uid' => $uid,
			]);
			return;
		}

		$boundFileId = (int)$existingBinding['file_id'];
		if ($boundFileId === $fileId) {
			// Defensive: this branch is unreachable today because we only get
			// here when the file has no YAML frontmatter, which implies no
			// binding tied to it either. If it happens anyway, just write the
			// frontmatter — the existing binding already covers us.
			$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
			$this->logger->info('Migrated legacy Ownpad .pad — binding already exists for this file.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'originBranch' => 'same',
				'accessMode' => $accessMode,
				'padId' => $sourcePadId,
				'collision' => 'self',
				'uid' => $uid,
			]);
			return;
		}

		try {
			$this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			$this->logger->warning('Refused legacy Ownpad migration — pad already bound to a file the user cannot read.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				'sourceUrl' => $sourceUrl,
				'padId' => $sourcePadId,
				'boundFileId' => $boundFileId,
				'collision' => 'no_access',
				'uid' => $uid,
			]);
			throw new LegacyPadCollisionException(
				'This pad is already linked to another file you do not have access to.',
			);
		}

		// Collision-with-access: write managed frontmatter but do NOT create
		// a second binding row. The file becomes a "copy of a pad" in our
		// model and the existing copy-handling flow takes over.
		$this->writeManagedFrontmatter($file, $fileId, $sourcePadId, $accessMode);
		$this->logger->info('Migrated legacy Ownpad .pad as copy of an already-bound pad.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
			'sourceUrl' => $sourceUrl,
			'originBranch' => 'same',
			'accessMode' => $accessMode,
			'padId' => $sourcePadId,
			'collision' => 'with_access',
			'boundFileId' => $boundFileId,
			'uid' => $uid,
		]);
	}

	private function writeManagedFrontmatter(File $file, int $fileId, string $padId, string $accessMode): void {
		$padUrl = $this->etherpadClient->buildPadUrl($padId);
		$content = $this->padFileService->buildInitialDocument(
			$fileId,
			$padId,
			$accessMode,
			'',
			$padUrl,
		);
		$file->putContent($content);
	}
}
