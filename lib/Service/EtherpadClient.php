<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IConfig;

class EtherpadClient {
	public const DEFAULT_API_VERSION = '1.2.15';

	private const EXTERNAL_REQUEST_TIMEOUT_SECONDS = 15;

	public function __construct(
		private IConfig $config,
		private AdminSettingsRepository $settingsRepository,
	) {
	}

	public function buildPadUrl(string $padId): string {
		return $this->getPublicHost() . '/p/' . rawurlencode($padId);
	}

	public function getText(string $padId): string {
		$data = $this->apiCall('getText', ['padID' => $padId]);
		return (string)($data['text'] ?? '');
	}

	public function getHTML(string $padId): string {
		$data = $this->apiCall('getHTML', ['padID' => $padId]);
		return (string)($data['html'] ?? '');
	}

	public function getRevisionsCount(string $padId): int {
		$data = $this->apiCall('getRevisionsCount', ['padID' => $padId]);
		$revisions = (int)($data['revisions'] ?? 0);
		return max(0, $revisions);
	}

	public function setText(string $padId, string $text): void {
		$this->apiCall('setText', ['padID' => $padId, 'text' => $text], 'POST');
	}

	public function setHTML(string $padId, string $html): void {
		$this->apiCall('setHTML', ['padID' => $padId, 'html' => $html], 'POST');
	}

	public function deletePad(string $padId): void {
		$this->apiCall('deletePad', ['padID' => $padId]);
	}

	public function createPad(string $padId): void {
		$this->apiCall('createPad', ['padID' => $padId]);
	}

	public function createGroup(): string {
		$data = $this->apiCall('createGroup');
		$groupId = (string)($data['groupID'] ?? '');
		if ($groupId === '') {
			throw new EtherpadClientException('Etherpad did not return groupID.');
		}
		return $groupId;
	}

	public function createGroupPad(string $groupId, string $padName): string {
		$data = $this->apiCall('createGroupPad', [
			'groupID' => $groupId,
			'padName' => $padName,
		]);

		$padId = (string)($data['padID'] ?? '');
		if ($padId === '') {
			throw new EtherpadClientException('Etherpad did not return group pad ID.');
		}
		return $padId;
	}

	public function createAuthorIfNotExistsFor(string $authorMapper, string $name): string {
		$data = $this->apiCall('createAuthorIfNotExistsFor', [
			'authorMapper' => $authorMapper,
			'name' => $name,
		]);

		$authorId = (string)($data['authorID'] ?? '');
		if ($authorId === '') {
			throw new EtherpadClientException('Etherpad did not return authorID.');
		}

		return $authorId;
	}

	public function setAuthorName(string $authorId, string $name): void {
		$this->apiCall('setAuthorName', [
			'authorID' => $authorId,
			'name' => $name,
		], 'POST');
	}

	public function createSession(string $groupId, string $authorId, int $validUntil): string {
		$data = $this->apiCall('createSession', [
			'groupID' => $groupId,
			'authorID' => $authorId,
			'validUntil' => $validUntil,
		]);

		$sessionId = (string)($data['sessionID'] ?? '');
		if ($sessionId === '') {
			throw new EtherpadClientException('Etherpad did not return sessionID.');
		}

		return $sessionId;
	}

	public function getReadOnlyPadUrl(string $padId): string {
		$data = $this->apiCall('getReadOnlyID', ['padID' => $padId]);
		$readOnlyId = (string)($data['readOnlyID'] ?? '');
		if ($readOnlyId === '') {
			throw new EtherpadClientException('Etherpad did not return readOnlyID.');
		}

		return $this->buildPadUrl($readOnlyId);
	}

	/** @return array{pad_count:int} */
	public function healthCheck(string $host, string $apiKey, string $apiVersion = self::DEFAULT_API_VERSION): array {
		$data = $this->apiCall('listAllPads', [], 'POST', $host, $apiKey, $apiVersion);
		$padIds = $data['padIDs'] ?? [];
		$padCount = is_array($padIds) ? count($padIds) : 0;
		return ['pad_count' => $padCount];
	}

	public function detectApiVersion(string $host): string {
		$url = rtrim(trim($host), '/') . '/api';
		$raw = $this->sendPublicGetRequest($url);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw new EtherpadClientException('Could not detect Etherpad API version.');
		}

		$version = '';
		foreach (['currentVersion', 'apiVersion', 'version'] as $candidateKey) {
			if (isset($decoded[$candidateKey]) && is_string($decoded[$candidateKey])) {
				$version = trim($decoded[$candidateKey]);
				if ($version !== '') {
					break;
				}
			}
		}

		if ($version === '' || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
			throw new EtherpadClientException('Could not detect Etherpad API version.');
		}

		return $version;
	}

	/** @return array<string,mixed> */
	private function apiCall(
		string $method,
		array $params = [],
		string $httpMethod = 'POST',
		?string $hostOverride = null,
		?string $apiKeyOverride = null,
		?string $apiVersionOverride = null
	): array {
		$apiVersion = $apiVersionOverride !== null && trim($apiVersionOverride) !== ''
			? trim($apiVersionOverride)
			: (string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_api_version', self::DEFAULT_API_VERSION);
		$host = $hostOverride !== null && trim($hostOverride) !== ''
			? rtrim(trim($hostOverride), '/')
			: $this->getApiHost();
		$apiKey = $apiKeyOverride !== null && trim($apiKeyOverride) !== ''
			? trim($apiKeyOverride)
			: $this->getApiKey();
		$url = sprintf('%s/api/%s/%s', $host, $apiVersion, $method);

		$query = array_merge($params, [
			'apikey' => $apiKey,
		]);

		try {
			$rawBody = $this->sendRequest($url, $query, $httpMethod);
		} catch (\Throwable $e) {
			throw new EtherpadClientException('Etherpad API request failed: ' . $method, 0, $e);
		}

		$decoded = json_decode($rawBody, true);
		if (!is_array($decoded)) {
			throw new EtherpadClientException('Invalid JSON response from Etherpad API.');
		}

		$code = (int)($decoded['code'] ?? -1);
		if ($code !== 0) {
			$message = (string)($decoded['message'] ?? 'Unknown Etherpad API error');
			throw new EtherpadClientException(sprintf('Etherpad API error (%s): %s', $method, $message));
		}

		$data = $decoded['data'] ?? [];
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string,mixed> $query
	 */
	private function sendRequest(string $url, array $query, string $httpMethod): string {
		$method = strtoupper($httpMethod);
		$queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		$targetUrl = $method === 'GET' ? ($url . '?' . $queryString) : $url;
		$context = $this->buildHttpContext(
			$method,
			"Accept: application/json\r\n",
			$method === 'POST' ? $queryString : null,
		);
		$body = @file_get_contents($targetUrl, false, $context);
		if ($body === false) {
			$error = error_get_last();
			$reason = $error['message'] ?? 'Unknown network error';
			throw new EtherpadClientException('Etherpad transport error: ' . $reason);
		}

		$statusCode = $this->extractStatusCode($http_response_header ?? []);
		if ($statusCode >= 400) {
			throw new EtherpadClientException('Etherpad API HTTP error (' . $statusCode . ')');
		}

		return $body;
	}

	/**
	 * @param list<string> $headers
	 */
	private function extractStatusCode(array $headers): int {
		foreach ($headers as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
				return (int)$matches[1];
			}
		}
		return 0;
	}

	private function getPublicHost(): string {
		$host = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''), '/');
		if ($host === '') {
			throw new EtherpadClientException('Etherpad host is not configured.');
		}
		return $host;
	}

	/**
	 * Returns the configured Etherpad origin (scheme + host + port,
	 * normalized) so callers can compare a foreign pad URL against "is this
	 * the server we manage?". Default ports (80/443) are omitted. Empty
	 * string when no host is configured — callers should treat that as
	 * "always cross-origin".
	 *
	 * Tolerant of http (unlike `parsePublicPadUrl` which enforces https)
	 * because admins may legitimately run Etherpad behind a plain-http
	 * internal endpoint while still wanting same-origin re-bind to work.
	 */
	public function getConfiguredOrigin(): string {
		$host = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''), '/');
		if ($host === '') {
			return '';
		}
		return $this->normalizeOrigin($host);
	}

	/**
	 * Normalize an absolute URL to a comparable origin string
	 * (scheme://host[:port]). Returns '' on unparseable input.
	 */
	public function normalizeOrigin(string $url): string {
		$parts = parse_url($url);
		if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$scheme = strtolower((string)$parts['scheme']);
		$host = strtolower((string)$parts['host']);
		$port = isset($parts['port']) ? (int)$parts['port'] : null;
		$isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
		if ($port === null || $isDefaultPort) {
			return $scheme . '://' . $host;
		}
		return $scheme . '://' . $host . ':' . $port;
	}

	private function getApiHost(): string {
		$apiHost = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_api_host', ''), '/');
		if ($apiHost !== '') {
			return $apiHost;
		}
		return $this->getPublicHost();
	}

	private function getApiKey(): string {
		// Single read path: AdminSettingsRepository owns reading the
		// sensitive (encrypted-at-rest) key via IAppConfig. Going through it
		// keeps the "must decrypt via IAppConfig" knowledge in one place.
		$key = $this->settingsRepository->getApiKey();
		if ($key === '') {
			throw new EtherpadClientException('Etherpad API key is not configured.');
		}
		return $key;
	}

	private function sendPublicGetRequest(string $url): string {
		$context = $this->buildHttpContext(
			'GET',
			"Accept: application/json\r\n",
			null,
			self::EXTERNAL_REQUEST_TIMEOUT_SECONDS
		);
		$body = @file_get_contents($url, false, $context);
		if ($body === false) {
			$error = error_get_last();
			$reason = $error['message'] ?? 'Unknown network error';
			throw new EtherpadClientException('HTTP transport error: ' . $reason);
		}

		$statusCode = $this->extractStatusCode($http_response_header ?? []);
		if ($statusCode >= 400) {
			throw new EtherpadClientException('HTTP error (' . $statusCode . ')');
		}

		return (string)$body;
	}

	private function buildHttpContext(string $method, string $headers, ?string $content = null, int $timeout = 15): mixed {
		$options = [
			'http' => [
				'method' => strtoupper($method),
				'timeout' => $timeout,
				'ignore_errors' => true,
				'follow_location' => 0,
				'max_redirects' => 0,
				'header' => $headers,
			],
		];
		if ($content !== null) {
			$options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
			$options['http']['content'] = $content;
		}
		return stream_context_create($options);
	}

}
