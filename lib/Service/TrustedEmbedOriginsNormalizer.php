<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCP\IL10N;

class TrustedEmbedOriginsNormalizer {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function normalize(string $rawOrigins): string {
		return implode("\n", $this->parse($rawOrigins, true));
	}

	/**
	 * @return list<string>
	 * @throws AdminValidationException
	 */
	public function parse(string $rawOrigins, bool $throwOnInvalid = false): array {
		$tokens = preg_split('/[\r\n,;]+/', trim($rawOrigins)) ?: [];
		$normalized = [];

		foreach ($tokens as $token) {
			$entry = trim($token);
			if ($entry === '') {
				continue;
			}

			$origin = $this->normalizeOrigin($entry, $throwOnInvalid);
			if ($origin !== '') {
				$normalized[$origin] = true;
			}
		}

		return array_keys($normalized);
	}

	private function normalizeOrigin(string $entry, bool $throwOnInvalid): string {
		$parts = parse_url($entry);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return $this->invalid($throwOnInvalid, 'Trusted embed origins must be absolute origins: {origin}', $entry);
		}
		if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
			return $this->invalid($throwOnInvalid, 'Trusted embed origins must not include a path: {origin}', $entry);
		}
		if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
			return $this->invalid($throwOnInvalid, 'Trusted embed origins must not include credentials, query, or fragment: {origin}', $entry);
		}

		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'https') {
			return $this->invalid($throwOnInvalid, 'Trusted embed origins must use https: {origin}', $entry);
		}

		$host = strtolower($parts['host']);
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}
		$origin = $scheme . '://' . $host;
		if (isset($parts['port'])) {
			$port = $parts['port'];
			if ($port < 1 || $port > 65535) {
				return $this->invalid($throwOnInvalid, 'Trusted embed origins must use a valid TCP port: {origin}', $entry);
			}
			// A browser leaves the default port out of an origin, and the
			// embed page compares the host's messages with these as they are.
			if ($port !== 443) {
				$origin .= ':' . $port;
			}
		}
		return $origin;
	}

	private function invalid(bool $throwOnInvalid, string $message, string $origin): string {
		if ($throwOnInvalid) {
			throw new AdminValidationException(
				'trusted_embed_origins',
				// Filled after translating: Nextcloud fills parameters with
				// vsprintf(), which knows no {name}.
				str_replace('{origin}', $origin, $this->l10n->t($message))
			);
		}
		return '';
	}
}
