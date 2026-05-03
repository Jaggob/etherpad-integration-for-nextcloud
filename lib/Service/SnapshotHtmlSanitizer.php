<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Sanitizes stored Etherpad snapshot HTML for read-only public previews.
 *
 * No attributes are preserved by design: links, styles, event handlers and
 * classes are all dropped. Unknown tags are unwrapped, while explicitly
 * dangerous/embedded content tags are removed with their content.
 */
class SnapshotHtmlSanitizer {
	private const FORBIDDEN_TAGS = [
		'script',
		'style',
		'iframe',
		'object',
		'embed',
		'svg',
		'math',
		'img',
		'video',
		'audio',
		'source',
		'link',
		'meta',
	];

	private const ALLOWED_TAGS = [
		'p',
		'br',
		'ul',
		'ol',
		'li',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'strong',
		'b',
		'em',
		'i',
		'u',
		's',
		'del',
		'blockquote',
		'pre',
		'code',
	];

	public function sanitize(string $html): string {
		$trimmed = trim($html);
		if ($trimmed === '') {
			return '';
		}

		$previous = libxml_use_internal_errors(true);
		$document = new \DOMDocument();
		$loaded = $document->loadHTML(
			'<?xml encoding="UTF-8">' . $trimmed,
			LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return '';
		}

		$body = $document->getElementsByTagName('body')->item(0);
		$root = $body instanceof \DOMNode ? $body : $document;
		$output = '';
		foreach ($root->childNodes as $child) {
			$output .= $this->sanitizeNode($child);
		}
		return trim($output);
	}

	private function sanitizeNode(\DOMNode $node): string {
		if ($node instanceof \DOMText || $node instanceof \DOMCdataSection) {
			return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		if (!$node instanceof \DOMElement) {
			return '';
		}

		$tag = strtolower($node->tagName);
		if (in_array($tag, self::FORBIDDEN_TAGS, true)) {
			return '';
		}

		$content = '';
		foreach ($node->childNodes as $child) {
			$content .= $this->sanitizeNode($child);
		}

		if (!in_array($tag, self::ALLOWED_TAGS, true)) {
			return $content;
		}
		if ($tag === 'br') {
			return '<br>';
		}
		return '<' . $tag . '>' . $content . '</' . $tag . '>';
	}
}
