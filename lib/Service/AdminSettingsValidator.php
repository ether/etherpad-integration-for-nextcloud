<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class AdminSettingsValidator {
	public function __construct(
		private IL10N $l10n,
		private AllowlistNormalizer $allowlistNormalizer,
		private TrustedEmbedOriginsNormalizer $trustedEmbedOriginsNormalizer,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Save and health-check currently share validation rules. The separate entry
	 * points keep endpoint intent explicit if persistence-only rules return later.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function validateForSave(array $payload, StoredAdminSettings $stored): ValidatedAdminSettings {
		$apiKey = $this->resolveApiKey($payload, $stored);
		return $this->validate($payload, $stored, $apiKey['to_store'], $apiKey['effective']);
	}

	/**
	 * See validateForSave(): health checks intentionally use the same normalized
	 * values as saving, without persisting them.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function validateForHealthCheck(array $payload, StoredAdminSettings $stored): ValidatedAdminSettings {
		$apiKey = $this->resolveApiKey($payload, $stored);
		return $this->validate($payload, $stored, $apiKey['to_store'], $apiKey['effective']);
	}

	/** @param array<string,mixed> $payload */
	private function validate(array $payload, StoredAdminSettings $stored, ?string $apiKeyToStore, string $effectiveApiKey): ValidatedAdminSettings {
		$host = $this->normalizeEtherpadHost((string)($payload['etherpad_host'] ?? ''));
		$apiHost = $this->normalizeEtherpadApiHost((string)($payload['etherpad_api_host'] ?? ''), $host);
		$cookieDomain = $this->normalizeCookieDomain((string)($payload['etherpad_cookie_domain'] ?? $stored->cookieDomain));
		$syncIntervalSeconds = $this->normalizeSyncInterval($payload['sync_interval_seconds'] ?? 120);
		$deleteOnTrash = $this->toBool($payload['delete_on_trash'] ?? $stored->deleteOnTrash);
		$allowExternalPads = $this->toBool($payload['allow_external_pads'] ?? $stored->allowExternalPads);
		$externalAllowlist = $this->allowlistNormalizer->normalize((string)($payload['external_pad_allowlist'] ?? ''));
		$trustedEmbedOrigins = $this->trustedEmbedOriginsNormalizer->normalize(
			(string)($payload['trusted_embed_origins'] ?? $stored->trustedEmbedOrigins)
		);

		return new ValidatedAdminSettings(
			$host,
			$apiHost,
			$cookieDomain,
			$apiKeyToStore,
			$effectiveApiKey,
			$this->resolveApiVersion((string)($payload['etherpad_api_version'] ?? ''), $apiHost),
			$syncIntervalSeconds,
			$deleteOnTrash,
			$allowExternalPads,
			$externalAllowlist,
			$trustedEmbedOrigins,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{to_store:?string,effective:string}
	 */
	private function resolveApiKey(array $payload, StoredAdminSettings $stored): array {
		$rawApiKey = trim((string)($payload['etherpad_api_key'] ?? ''));
		$effectiveApiKey = $rawApiKey !== '' ? $rawApiKey : $stored->apiKey;
		if ($effectiveApiKey === '') {
			throw new AdminValidationException('etherpad_api_key', $this->l10n->t('Etherpad API key is required.'));
		}
		return [
			'to_store' => $rawApiKey === '' ? null : $rawApiKey,
			'effective' => $effectiveApiKey,
		];
	}

	private function normalizeEtherpadHost(string $rawHost): string {
		$trimmed = trim($rawHost);
		if ($trimmed === '') {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL is required.'));
		}
		if (preg_match('#^https://#i', $trimmed) !== 1) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL must use https.'));
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Invalid Etherpad Base URL.'));
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			throw new AdminValidationException('etherpad_host', $this->l10n->t('Etherpad Base URL must not include query or fragment.'));
		}

		return $this->normalizeHostUrl($parts);
	}

	private function normalizeEtherpadApiHost(string $rawHost, string $fallbackPublicHost): string {
		$trimmed = trim($rawHost);
		if ($trimmed === '') {
			return $fallbackPublicHost;
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Invalid Etherpad API URL.'));
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Etherpad API URL must not include query or fragment.'));
		}

		$scheme = strtolower((string)$parts['scheme']);
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new AdminValidationException('etherpad_api_host', $this->l10n->t('Etherpad API URL must use http or https.'));
		}

		return $this->normalizeHostUrl($parts);
	}

	/** @param array<string,mixed> $parts */
	private function normalizeHostUrl(array $parts): string {
		$scheme = strtolower((string)$parts['scheme']);
		$host = strtolower((string)$parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : 0;
		$path = trim((string)($parts['path'] ?? ''));

		$normalizedHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
			? '[' . $host . ']'
			: $host;
		$normalized = $scheme . '://' . $normalizedHost;
		if ($port > 0) {
			$normalized .= ':' . $port;
		}
		if ($path !== '') {
			$normalized .= '/' . ltrim($path, '/');
			$normalized = rtrim($normalized, '/');
		}
		return $normalized;
	}

	private function normalizeCookieDomain(string $rawDomain): string {
		$domain = strtolower(trim($rawDomain));
		if ($domain === '') {
			return '';
		}

		if (str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')) {
			throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a hostname, not a URL.'));
		}

		$isParentDomain = str_starts_with($domain, '.');
		$host = ltrim($domain, '.');
		if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
			throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a valid shared hostname.'));
		}

		foreach (explode('.', $host) as $label) {
			if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
				throw new AdminValidationException('etherpad_cookie_domain', $this->l10n->t('Cookie domain must be a valid shared hostname.'));
			}
		}

		return ($isParentDomain ? '.' : '') . $host;
	}

	private function normalizeApiVersion(string $rawVersion): string {
		$version = trim($rawVersion);
		if ($version === '') {
			return EtherpadClient::DEFAULT_API_VERSION;
		}
		if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
			throw new AdminValidationException('etherpad_api_version', $this->l10n->t('Invalid Etherpad API version format.'));
		}
		return $version;
	}

	private function normalizeSyncInterval(mixed $value): int {
		$interval = (int)$value;
		if ($interval < 5 || $interval > 3600) {
			throw new AdminValidationException('sync_interval_seconds', $this->l10n->t('Sync interval must be between 5 and 3600 seconds.'));
		}
		return $interval;
	}

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return (int)$value !== 0;
		}
		$normalized = strtolower(trim((string)$value));
		return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
	}

	private function resolveApiVersion(string $rawVersion, string $host): string {
		$manual = trim($rawVersion);
		if ($manual !== '') {
			return $this->normalizeApiVersion($manual);
		}

		try {
			return $this->normalizeApiVersion($this->etherpadClient->detectApiVersion($host));
		} catch (EtherpadClientException $e) {
			$this->logger->info('Etherpad API version auto-detection failed; using default API version.', [
				'app' => 'etherpad_nextcloud',
				'host' => $host,
				'exception' => $e,
			]);
			return EtherpadClient::DEFAULT_API_VERSION;
		}
	}
}
