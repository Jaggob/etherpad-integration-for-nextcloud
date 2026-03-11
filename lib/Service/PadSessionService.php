<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IConfig;

class PadSessionService {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
	) {
	}

	/** @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}} */
	public function createProtectedOpenContext(string $uid, string $displayName, string $padId, int $ttlSeconds = 3600): array {
		$groupId = $this->extractGroupId($padId);
		$effectiveDisplayName = trim($displayName) !== '' ? $displayName : $uid;
		$authorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $effectiveDisplayName);
		$safeTtlSeconds = max(60, $ttlSeconds);
		$validUntil = time() + $safeTtlSeconds;
		$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie($sessionId, $validUntil),
		];
	}

	public function extractGroupId(string $padId): string {
		if (preg_match('/^(g\.[A-Za-z0-9]{16})\$/', $padId, $matches) !== 1) {
			throw new EtherpadClientException('Protected pad ID is invalid (group prefix missing).');
		}
		return $matches[1];
	}

	/** @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} */
	private function buildEtherpadSessionCookie(string $sessionId, int $validUntil): array {
		$cookieDomain = (string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_cookie_domain', '');
		if ($cookieDomain === '') {
			$cookieDomain = $this->deriveCookieDomainFromHost();
		}
		return [
			'name' => 'sessionID',
			'value' => $sessionId,
			'expires' => $validUntil,
			'path' => '/',
			'domain' => $cookieDomain,
			'secure' => true,
			'http_only' => false,
			'same_site' => 'None',
		];
	}

	/** @param array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} $cookie */
	public function buildSetCookieHeader(array $cookie): string {
		$parts = [];
		$parts[] = rawurlencode($cookie['name']) . '=' . rawurlencode($cookie['value']);
		$parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $cookie['expires']);
		$maxAge = max(0, $cookie['expires'] - time());
		$parts[] = 'Max-Age=' . $maxAge;
		$parts[] = 'Path=' . ($cookie['path'] !== '' ? $cookie['path'] : '/');
		if ($cookie['domain'] !== '') {
			$parts[] = 'Domain=' . $cookie['domain'];
		}
		if ($cookie['secure']) {
			$parts[] = 'Secure';
		}
		if ($cookie['http_only']) {
			$parts[] = 'HttpOnly';
		}
		if (($cookie['same_site'] ?? '') !== '') {
			$parts[] = 'SameSite=' . $cookie['same_site'];
		}
		return implode('; ', $parts);
	}

	private function deriveCookieDomainFromHost(): string {
		$host = (string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', '');
		if ($host === '') {
			return '';
		}

		$parsedHost = parse_url($host, PHP_URL_HOST);
		if (!is_string($parsedHost) || $parsedHost === '') {
			return '';
		}
		$parsedHost = strtolower(trim($parsedHost));
		if ($parsedHost === '' || filter_var($parsedHost, FILTER_VALIDATE_IP) !== false || !str_contains($parsedHost, '.')) {
			return '';
		}

		$labels = explode('.', $parsedHost);
		if (count($labels) === 2) {
			return $parsedHost;
		}
		if (count($labels) < 2) {
			return '';
		}

		array_shift($labels);
		return '.' . implode('.', $labels);
	}
}
