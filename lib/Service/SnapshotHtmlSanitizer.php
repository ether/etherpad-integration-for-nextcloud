<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Sanitizes Etherpad HTML for the read-only views.
 *
 * One attribute survives, on one tag: `href` on `<a>`, and only when it
 * names http, https or mailto. Everything else — styles, event handlers,
 * classes, every attribute on every other tag — is dropped. Unknown tags
 * are unwrapped, while explicitly dangerous/embedded content tags are
 * removed with their content.
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
		'a',
	];

	/**
	 * An allowlist rather than a `javascript:` denylist: everything a
	 * denylist would have to anticipate — `data:`, `vbscript:`, a scheme
	 * some browser adds later — is simply not on this list.
	 */
	private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto'];

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

	/** Returns the href to emit, or `''` when it may not be emitted at all. */
	private function safeLinkTarget(string $href): string {
		// Browsers ignore control characters while reading a scheme, so
		// `java\tscript:` is `javascript:` to them — and so it is here.
		$candidate = (string)preg_replace('/[\x00-\x20\x7F]/', '', $href);
		if ($candidate === '') {
			return '';
		}

		// No scheme means a relative link, which inside a pad would point at
		// this Nextcloud rather than anywhere the pad meant.
		$colon = strpos($candidate, ':');
		if ($colon === false) {
			return '';
		}

		return in_array(strtolower(substr($candidate, 0, $colon)), self::ALLOWED_LINK_SCHEMES, true)
			? $candidate
			: '';
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
		if ($tag === 'a') {
			$href = $this->safeLinkTarget($node->getAttribute('href'));
			// A link with nowhere safe to go is still text worth keeping.
			if ($href === '') {
				return $content;
			}

			return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '" target="_blank" rel="noopener noreferrer">' . $content . '</a>';
		}
		return '<' . $tag . '>' . $content . '</' . $tag . '>';
	}
}
