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

	private const EXTERNAL_EXPORT_MAX_BYTES = 5242880; // 5 MiB
	private const EXTERNAL_REQUEST_TIMEOUT_SECONDS = 15;

	public function __construct(
		private IConfig $config,
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

	public function getPublicTextFromPadUrl(string $padUrl): string {
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		return $this->getPublicTextFromResolvedExternalPad($resolved);
	}

	/** @return array{origin:string,pad_id:string,pad_url:string,text:string} */
	public function normalizeAndFetchExternalPublicPadText(string $padUrl): array {
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		return [
			'origin' => $resolved['origin'],
			'pad_id' => $resolved['pad_id'],
			'pad_url' => $resolved['pad_url'],
			'text' => $this->getPublicTextFromResolvedExternalPad($resolved),
		];
	}

	public function assertPublicPadAvailable(string $padUrl): void {
		$this->getPublicTextFromPadUrl($padUrl);
	}

	/** @return array{origin:string,pad_id:string,pad_url:string} */
	public function normalizeAndValidateExternalPublicPadUrl(string $padUrl): array {
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		return [
			'origin' => $resolved['origin'],
			'pad_id' => $resolved['pad_id'],
			'pad_url' => $resolved['pad_url'],
		];
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

	private function getApiHost(): string {
		$apiHost = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_api_host', ''), '/');
		if ($apiHost !== '') {
			return $apiHost;
		}
		return $this->getPublicHost();
	}

	private function getApiKey(): string {
		$key = (string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_api_key', '');
		if ($key === '') {
			throw new EtherpadClientException('Etherpad API key is not configured.');
		}
		return $key;
	}

	private function buildPublicExportUrl(string $padUrl, string $format): string {
		$parsed = $this->parsePublicPadUrl($padUrl);
		return $parsed['pad_url'] . '/export/' . $format;
	}

	/**
	 * @param array{pad_url:string,host:string,port:int,resolved_ips:list<string>} $resolved
	 */
	private function getPublicTextFromResolvedExternalPad(array $resolved): string {
		$url = $this->buildPublicExportUrl($resolved['pad_url'], 'txt');
		return $this->sendPinnedPublicGetRequest($url, $resolved['host'], $resolved['port'], $resolved['resolved_ips']);
	}

	/** @return array{origin:string,pad_id:string,pad_url:string,host:string,port:int,resolved_ips:list<string>} */
	private function resolveAndValidateExternalPublicPadUrl(string $padUrl): array {
		$parsed = $this->parsePublicPadUrl($padUrl);
		$padId = $parsed['pad_id'];
		if (preg_match('/^g\.[^$]+\$.+$/', $padId) === 1) {
			throw new EtherpadClientException('Only public pad URLs can be linked from external servers.');
		}

		return [
			'origin' => $parsed['origin'],
			'pad_id' => $parsed['pad_id'],
			'pad_url' => $parsed['pad_url'],
			'host' => $parsed['host'],
			'port' => $parsed['port'],
			'resolved_ips' => $this->resolveAndValidateExternalHost($parsed['host'], $parsed['origin']),
		];
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

	/**
	 * @param list<string> $resolvedIps
	 */
	private function sendPinnedPublicGetRequest(string $url, string $host, int $port, array $resolvedIps): string {
		if (!function_exists('curl_init')) {
			throw new EtherpadClientException('External pad sync requires PHP cURL extension.');
		}

		$errors = [];
		foreach ($resolvedIps as $ip) {
			$buffer = '';
			$contentType = '';
			$sizeExceeded = false;
			$maxBytes = self::EXTERNAL_EXPORT_MAX_BYTES;
			$curl = curl_init($url);
			if ($curl === false) {
				throw new EtherpadClientException('Could not initialize external export request.');
			}
			$curlOptions = [
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_MAXREDIRS => 0,
				CURLOPT_CONNECTTIMEOUT => self::EXTERNAL_REQUEST_TIMEOUT_SECONDS,
				CURLOPT_TIMEOUT => self::EXTERNAL_REQUEST_TIMEOUT_SECONDS,
				CURLOPT_HTTPGET => true,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER => [
					'Accept: text/plain, application/octet-stream;q=0.9, */*;q=0.1',
				],
				CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
				CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$contentType): int {
					$line = trim($headerLine);
					if ($line !== '' && stripos($line, 'Content-Type:') === 0) {
						$contentType = trim((string)substr($line, strlen('Content-Type:')));
					}
					return strlen($headerLine);
				},
				CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$sizeExceeded, $maxBytes): int {
					if (strlen($buffer) + strlen($chunk) > $maxBytes) {
						$sizeExceeded = true;
						return 0;
					}
					$buffer .= $chunk;
					return strlen($chunk);
				},
			];
			if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
				$curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
			}
			if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
				$curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
			}
			curl_setopt_array($curl, $curlOptions);

			$success = curl_exec($curl);
			$httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
			$curlError = curl_error($curl);
			curl_close($curl);

			if ($success === false) {
				if ($sizeExceeded) {
					throw new EtherpadClientException(
						'External public pad export exceeds maximum size (' . self::EXTERNAL_EXPORT_MAX_BYTES . ' bytes).'
					);
				}
				$errors[] = 'transport via ' . $ip . ': ' . ($curlError !== '' ? $curlError : 'unknown error');
				continue;
			}
			if ($httpCode >= 400) {
				throw new EtherpadClientException('Public export HTTP error (' . $httpCode . ')');
			}

			$this->assertAllowedExternalExportContentType($contentType);
			return $buffer;
		}

		$detail = $errors !== [] ? implode('; ', $errors) : 'all resolved targets failed';
		throw new EtherpadClientException('Public export transport error: ' . $detail);
	}

	private function assertAllowedExternalExportContentType(string $contentTypeHeader): void {
		$raw = trim($contentTypeHeader);
		if ($raw === '') {
			throw new EtherpadClientException('Public export did not provide a Content-Type header.');
		}

		$normalized = strtolower(trim((string)explode(';', $raw, 2)[0]));
		if ($normalized === 'text/html') {
			throw new EtherpadClientException('Public export returned unsupported Content-Type: text/html');
		}
		if (str_starts_with($normalized, 'text/')) {
			return;
		}
		if (in_array($normalized, ['application/octet-stream', 'application/text'], true)) {
			return;
		}

		throw new EtherpadClientException('Public export returned unsupported Content-Type: ' . $normalized);
	}

	/** @return list<string> */
	private function resolveAndValidateExternalHost(string $host, string $origin): array {
		if ((string)$this->config->getAppValue('etherpad_nextcloud', 'allow_external_pads', 'no') !== 'yes') {
			throw new EtherpadClientException('External pad linking is disabled by admin settings.');
		}
		if (!$this->isAllowlistedExternalHost($host, $origin)) {
			throw new EtherpadClientException('External pad host is not in the allowlist.');
		}
		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
			throw new EtherpadClientException('Local hosts are not allowed for external pad sync.');
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if (!$this->isPublicIp($host)) {
				throw new EtherpadClientException('Private/reserved IPs are not allowed for external pad sync.');
			}
			return [$host];
		}

		$records = @dns_get_record($host, DNS_A + DNS_AAAA);
		if (!is_array($records) || $records === []) {
			throw new EtherpadClientException('Could not resolve external pad host.');
		}

		$resolvedIps = [];
		foreach ($records as $record) {
			if (isset($record['ip']) && is_string($record['ip']) && $record['ip'] !== '') {
				$resolvedIps[] = $record['ip'];
			}
			if (isset($record['ipv6']) && is_string($record['ipv6']) && $record['ipv6'] !== '') {
				$resolvedIps[] = $record['ipv6'];
			}
		}
		if ($resolvedIps === []) {
			throw new EtherpadClientException('Could not resolve external pad host to IP.');
		}

		foreach ($resolvedIps as $ip) {
			if (!$this->isPublicIp($ip)) {
				throw new EtherpadClientException('Private/reserved IPs are not allowed for external pad sync.');
			}
		}

		return array_values(array_unique($resolvedIps));
	}

	private function isPublicIp(string $ip): bool {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	private function isAllowlistedExternalHost(string $host, string $origin): bool {
		$raw = trim((string)$this->config->getAppValue('etherpad_nextcloud', 'external_pad_allowlist', ''));
		if ($raw === '') {
			return true;
		}

		$entries = preg_split('/[\s,;]+/', $raw) ?: [];
		$hostLower = strtolower($host);
		$originLower = strtolower($origin);
		foreach ($entries as $entry) {
			$normalized = strtolower(trim($entry));
			if ($normalized === '') {
				continue;
			}
			if (preg_match('#^https?://#i', $normalized) === 1) {
				if ($this->normalizeAllowlistOrigin($normalized) === $originLower) {
					return true;
				}
				continue;
			}
			if (trim($normalized, ". \t\n\r\0\x0B") === $hostLower) {
				return true;
			}
		}

		return false;
	}

	private function normalizeAllowlistOrigin(string $entry): string {
		$parts = parse_url($entry);
		if (!is_array($parts)) {
			return '';
		}
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		$port = isset($parts['port']) ? (int)$parts['port'] : 443;
		if ($scheme !== 'https' || $host === '' || $port <= 0 || $port > 65535) {
			return '';
		}
		return $port === 443 ? 'https://' . $host : 'https://' . $host . ':' . $port;
	}

	/** @return array{origin:string,pad_id:string,pad_url:string,host:string,port:int} */
	private function parsePublicPadUrl(string $padUrl): array {
		$trimmed = trim($padUrl);
		if ($trimmed === '' || preg_match('#^https?://#i', $trimmed) !== 1) {
			throw new EtherpadClientException('Invalid public pad URL.');
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts)) {
			throw new EtherpadClientException('Invalid public pad URL.');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new EtherpadClientException('Public pad URL must not contain credentials.');
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		$port = isset($parts['port']) ? (int)$parts['port'] : 443;
		$path = (string)($parts['path'] ?? '');
		$decodedPath = urldecode($path);
		if ($scheme !== 'https' || $host === '' || $decodedPath === '' || $port <= 0 || $port > 65535) {
			throw new EtherpadClientException('Invalid public pad URL.');
		}

		if (preg_match('~^(.*)/p/([^/]+)$~', $decodedPath, $matches) !== 1) {
			throw new EtherpadClientException('Public pad URL must match /p/{padId}.');
		}

		$basePath = rtrim((string)$matches[1], '/');
		$padId = trim((string)$matches[2]);
		if ($padId === '') {
			throw new EtherpadClientException('Invalid public pad URL.');
		}

		$origin = $scheme . '://' . $host;
		if ($port !== 443) {
			$origin .= ':' . $port;
		}
		$normalizedBasePath = $basePath === '' ? '' : $basePath;
		$canonicalPadUrl = $origin . $normalizedBasePath . '/p/' . rawurlencode($padId);

		return [
			'origin' => $origin,
			'pad_id' => $padId,
			'pad_url' => $canonicalPadUrl,
			'host' => $host,
			'port' => $port,
		];
	}
}
