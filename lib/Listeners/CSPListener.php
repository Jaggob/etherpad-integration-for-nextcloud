<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class CSPListener implements IEventListener {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$host = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''), '/');
		if ($host === '') {
			return;
		}

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($host);
		$policy->addAllowedChildSrcDomain($host);
		$event->addPolicy($policy);
	}
}
