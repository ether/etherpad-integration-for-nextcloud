<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;

class EtherpadClient {
	/**
	 * Failsafe fallback API version, used only when auto-detection fails AND no
	 * etherpad_api_version is stored. Deliberately kept LOW, not bumped to the
	 * newest release: Etherpad's API version list is cumulative, so a newer
	 * server accepts any older version while rejecting one higher than it
	 * supports. Requesting a low version therefore maximises compatibility in
	 * exactly the degraded path where this constant matters, and the plugin
	 * uses no method newer than ~1.2.1 (it never passes the authorId param that
	 * 1.3.0 added). Only raise this if we start relying on a newer-API feature.
	 */
	public const DEFAULT_API_VERSION = '1.2.15';

	private const REQUEST_TIMEOUT_SECONDS = 15;

	public function __construct(
		private IConfig $config,
		private AdminSettingsRepository $settingsRepository,
		private IClientService $clientService,
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

	/**
	 * The pads inside a group. Answers `groupID does not exist` for a group
	 * that is not there, which for a pad inside it means the pad is not
	 * there either.
	 *
	 * Read strictly, because of what an empty answer now means. The caller
	 * takes `[]` as "this group holds nothing, removing it takes nothing
	 * with it" — so a shrugged-off answer would be a licence to delete. A
	 * missing field, a non-array, a member that is not a string: each of
	 * those is an Etherpad that did not answer the question, not a group
	 * that is empty. Saying so drops the caller back to deleting the pad
	 * alone, which is what it did before it could ask.
	 *
	 * @return list<string>
	 */
	public function listPads(string $groupId): array {
		$data = $this->apiCall('listPads', ['groupID' => $groupId]);
		if (!array_key_exists('padIDs', $data) || !is_array($data['padIDs']) || !array_is_list($data['padIDs'])) {
			throw new EtherpadClientException('Etherpad did not return a pad list for the group.');
		}

		$padIds = [];
		foreach ($data['padIDs'] as $padId) {
			if (!is_string($padId)) {
				throw new EtherpadClientException('Etherpad returned a pad list with a non-string entry.');
			}
			$padIds[] = $padId;
		}
		return $padIds;
	}

	/**
	 * Removes the group, every pad inside it, and every session that granted
	 * access to it — which is what makes it the right call for a protected
	 * pad. deletePad() on a group pad leaves the group and its sessions
	 * behind, and nothing else ever collects them.
	 */
	public function deleteGroup(string $groupId): void {
		$this->apiCall('deleteGroup', ['groupID' => $groupId]);
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

	/**
	 * The author's sessions, keyed by session id, each carrying the group it
	 * grants access to and when it stops doing so.
	 *
	 * Etherpad keeps them until they are deleted — an author who has opened
	 * protected pads for a while accumulates hundreds, nearly all expired —
	 * so callers must filter by validUntil rather than trust the list.
	 *
	 * @return array<string,array{groupID:string,validUntil:int}>
	 */
	public function listSessionsOfAuthor(string $authorId): array {
		// POST like every other authenticated call: a GET would put the
		// apikey in the URL, and from there into proxy and access logs.
		$data = $this->apiCall('listSessionsOfAuthor', ['authorID' => $authorId]);

		$sessions = [];
		foreach ($data as $sessionId => $info) {
			if (!is_string($sessionId) || !is_array($info)) {
				continue;
			}
			$groupId = (string)($info['groupID'] ?? '');
			$validUntil = (int)($info['validUntil'] ?? 0);
			if ($groupId === '' || $validUntil <= 0) {
				continue;
			}
			$sessions[$sessionId] = ['groupID' => $groupId, 'validUntil' => $validUntil];
		}

		return $sessions;
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

	/**
	 * The Etherpad release this instance is running, as `/health` reports it.
	 *
	 * Not the API version: `/api` answers `1.3.1` on both Etherpad 2.7.3 and
	 * 3.3.3, so it cannot tell the two apart. `releaseId` can, and the one
	 * thing that turns on it — whether Etherpad still needs to read its
	 * session cookie from JavaScript — changed between those two majors.
	 *
	 * `/health` needs no api key, which is why it is asked rather than an
	 * API method: this runs on the open path, and the key belongs in as few
	 * places as possible.
	 */
	public function detectReleaseVersion(): string {
		$raw = $this->sendPublicGetRequest($this->getApiHost() . '/health');
		$decoded = json_decode($raw, true);
		$release = is_array($decoded) && isset($decoded['releaseId']) && is_string($decoded['releaseId'])
			? trim($decoded['releaseId'])
			: '';

		if (preg_match('/^\d+\.\d+\.\d+/', $release) !== 1) {
			throw new EtherpadClientException('Could not detect the Etherpad release version.');
		}

		return $release;
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
		$options = $this->baseRequestOptions();
		if ($method === 'GET') {
			$options['query'] = $query;
		} else {
			// Keep the historical form-urlencoded body so the Etherpad API
			// (apikey + params) is sent exactly as before.
			$options['body'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
			$options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		}

		$response = $this->doRequest($method, $url, $options);
		$statusCode = $response->getStatusCode();
		if ($statusCode >= 400) {
			throw new EtherpadClientException('Etherpad API HTTP error (' . $statusCode . ')');
		}

		return (string)$response->getBody();
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
		$scheme = strtolower($parts['scheme']);
		$host = strtolower($parts['host']);
		$port = isset($parts['port']) ? $parts['port'] : null;
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
		$response = $this->doRequest('GET', $url, $this->baseRequestOptions());
		$statusCode = $response->getStatusCode();
		if ($statusCode >= 400) {
			throw new EtherpadClientException('HTTP error (' . $statusCode . ')');
		}

		return (string)$response->getBody();
	}

	/**
	 * Shared request options for every Etherpad call: a fixed timeout, the
	 * JSON Accept header, and redirects disabled (Etherpad never legitimately
	 * redirects an API call, and following one could leak the apikey to a
	 * foreign host).
	 *
	 * `allow_local_address` is enabled because the Etherpad API host is
	 * admin-configured and very commonly a loopback/LAN address (e.g.
	 * http://localhost:9001 behind the same box). Nextcloud's HTTP client
	 * blocks local addresses by default for SSRF safety; that protection is
	 * meant for user-supplied URLs, not this trusted, admin-only endpoint.
	 * External (user-reachable) pad fetching lives in ExternalPadExportFetcher
	 * and keeps its own public-IP / DNS-rebinding guards.
	 *
	 * @return array<string,mixed>
	 */
	private function baseRequestOptions(): array {
		return [
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
			'allow_redirects' => ['max' => 0],
			'headers' => ['Accept' => 'application/json'],
			'nextcloud' => ['allow_local_address' => true],
		];
	}

	/**
	 * Perform the HTTP request through Nextcloud's HTTP client (honouring the
	 * instance's proxy / TLS configuration). The NC client throws on >= 400,
	 * so we recover the real response via getResponseFromThrowable() to keep
	 * the status-code handling at the call site; a throwable without a
	 * response is a genuine transport failure.
	 *
	 * @param array<string,mixed> $options
	 */
	private function doRequest(string $method, string $url, array $options): IResponse {
		$client = $this->clientService->newClient();
		try {
			return $client->request($method, $url, $options);
		} catch (\Throwable $e) {
			try {
				return $client->getResponseFromThrowable($e);
			} catch (\Throwable) {
				throw new EtherpadClientException('Etherpad transport error: ' . $e->getMessage());
			}
		}
	}

}
