<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'etherpad_nextcloud_pads';
	}

	public function getName(): string {
		return $this->l10n->t('Pads');
	}

	public function getPriority(): int {
		return 55;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('etherpad_nextcloud', 'etherpad-icon-black.svg');
	}
}
