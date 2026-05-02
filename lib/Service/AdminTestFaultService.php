<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\AdminDebugModeRequiredException;
use OCA\EtherpadNextcloud\Exception\UnsupportedTestFaultException;
use OCP\IConfig;

class AdminTestFaultService {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function setFault(string $fault): string {
		if (!$this->config->getSystemValueBool('debug', false)) {
			throw new AdminDebugModeRequiredException();
		}

		$allowed = LifecycleService::getSupportedTestFaults();
		if ($fault !== '' && !in_array($fault, $allowed, true)) {
			throw new UnsupportedTestFaultException($allowed);
		}

		$this->config->setAppValue(Application::APP_ID, 'test_fault', $fault);
		return $fault;
	}
}
