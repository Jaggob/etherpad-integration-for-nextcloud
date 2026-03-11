<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IL10N;

class RegisterTemplateCreatorListener implements IEventListener {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof RegisterTemplateCreatorEvent)) {
			return;
		}

		$event->getTemplateManager()->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New pad'), '.pad');
			$creator->addMimetype('application/x-etherpad-nextcloud');
			$creator->setActionLabel($this->l10n->t('New pad'));
			$creator->setOrder(98);
			$iconPath = __DIR__ . '/../../img/etherpad-icon-color.svg';
			if (is_file($iconPath)) {
				$icon = file_get_contents($iconPath);
				if (is_string($icon) && $icon !== '') {
					$creator->setIconSvgInline($icon);
				}
			}
			return $creator;
		});
	}
}
