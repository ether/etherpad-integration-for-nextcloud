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

class AllowlistNormalizer {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function normalize(string $rawAllowlist): string {
		$tokens = preg_split('/[\s,;]+/', trim($rawAllowlist)) ?: [];
		$normalized = [];
		foreach ($tokens as $token) {
			$entry = trim($token);
			if ($entry === '') {
				continue;
			}

			if (preg_match('#^https?://#i', $entry) === 1) {
				$normalized[$this->normalizeUrlEntry($entry)] = true;
				continue;
			}

			$normalized[$this->normalizeHost($entry, $token)] = true;
		}

		return implode("\n", array_keys($normalized));
	}

	private function normalizeUrlEntry(string $entry): string {
		$parts = parse_url($entry);
		if (!is_array($parts)) {
			throw new AdminValidationException(
				'external_pad_allowlist',
				str_replace('{host}', $entry, $this->l10n->t('External allowlist URL must use https: {host}'))
			);
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');
		$port = isset($parts['port']) ? $parts['port'] : 443;
		$path = $parts['path'] ?? '';

		if ($scheme !== 'https' || $host === '' || $port <= 0 || $port > 65535 || ($path !== '' && $path !== '/')
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
		) {
			throw new AdminValidationException(
				'external_pad_allowlist',
				str_replace('{host}', $entry, $this->l10n->t('External allowlist URL must use https: {host}'))
			);
		}

		$normalizedHost = $this->normalizeHost($host, $entry);
		return $port === 443 ? 'https://' . $normalizedHost : 'https://' . $normalizedHost . ':' . $port;
	}

	private function normalizeHost(string $rawHost, string $sourceToken): string {
		$host = strtolower(trim($rawHost, ". \t\n\r\0\x0B"));
		if ($host === '' || str_contains($host, '..') || str_starts_with($host, '-') || str_ends_with($host, '-')) {
			throw $this->invalidHost($sourceToken);
		}
		if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
			throw $this->invalidHost($sourceToken);
		}
		return $host;
	}

	private function invalidHost(string $sourceToken): AdminValidationException {
		return new AdminValidationException(
			'external_pad_allowlist',
			str_replace('{host}', $sourceToken, $this->l10n->t('External allowlist contains invalid host: {host}'))
		);
	}
}
