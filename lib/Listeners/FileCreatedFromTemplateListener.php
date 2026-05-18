<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Hooks into Nextcloud's native "+ New pad" template flow. NC has already
 * byte-copied the template into the target path before this event fires, so
 * the heavy lifting (parse → resolve placeholders → provision pad → seed
 * snapshot → rewrite file with fresh frontmatter → create binding) is shared
 * with `PadCreationService::materializeTemplateInto` to keep both entry
 * points (NC native picker + custom-frontend API) in lockstep.
 *
 * On any skip or failure path the target is reset to empty so NC's normal
 * missing-frontmatter init handles the file on first open instead of the
 * user inheriting the template's source frontmatter/pad_id.
 */
class FileCreatedFromTemplateListener implements IEventListener {
	public function __construct(
		private PadCreationService $padCreationService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof FileCreatedFromTemplateEvent)) {
			return;
		}
		$target = $event->getTarget();
		if (!$target instanceof File) {
			return;
		}
		if (!str_ends_with(strtolower($target->getName()), '.pad')) {
			return;
		}
		$template = $event->getTemplate();
		if (!$template instanceof File) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Template flow without a session is unexpected (NC always
			// dispatches inside a logged-in request) but we refuse to render
			// a half-resolved pad: wipe the byte-copy and let normal init
			// kick in on first open.
			$this->logger->warning('Template event fired without an active user — resetting target to empty.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
			]);
			$this->resetTargetToEmpty($target);
			return;
		}

		try {
			$this->padCreationService->materializeTemplateInto($target, $template, $user);
		} catch (\Throwable $e) {
			$this->logger->error('Pad template materialization failed — resetting target to empty.', [
				'app' => 'etherpad_nextcloud',
				'targetFileId' => (int)$target->getId(),
				'templateFileId' => (int)$template->getId(),
				'exception' => $e,
			]);
			// Wipe whatever NC byte-copied so the new file becomes a regular
			// empty .pad and the next open initialises frontmatter normally.
			$this->resetTargetToEmpty($target);
		}
	}

	private function resetTargetToEmpty(File $target): void {
		try {
			$target->putContent('');
		} catch (\Throwable $e) {
			$this->logger->warning('Could not reset target file content after rejected template.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => (int)$target->getId(),
				'exception' => $e,
			]);
		}
	}
}
