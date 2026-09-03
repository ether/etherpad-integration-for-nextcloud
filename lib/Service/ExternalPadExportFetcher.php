<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCP\IConfig;

class ExternalPadExportFetcher {
	private const EXTERNAL_EXPORT_MAX_BYTES = 5242880; // 5 MiB
	private const EXTERNAL_REQUEST_TIMEOUT_SECONDS = 15;

	/**
	 * The whole fetch, not each attempt.
	 *
	 * A host can resolve to several addresses, and every one of them used to
	 * get the full timeout — so a name with four unreachable records held a
	 * request for a minute. The budget is shared now: what an attempt may
	 * take is whatever is left of it, and one that no longer fits is not
	 * made.
	 */
	private const EXTERNAL_TOTAL_BUDGET_SECONDS = 20;
	private const EXTERNAL_MIN_ATTEMPT_SECONDS = 2;

	public function __construct(
		private IConfig $config,
	) {
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

	/**
	 * @return array{origin:string,pad_id:string,pad_url:string,text:string,snapshot_unavailable:bool}
	 */
	public function normalizeAndFetchExternalPublicPadTextOrEmpty(string $padUrl): array {
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		$snapshotUnavailable = false;
		try {
			$text = $this->getPublicTextFromResolvedExternalPad($resolved);
		} catch (ExternalPadExportNotFoundException) {
			$text = '';
			$snapshotUnavailable = true;
		}

		return [
			'origin' => $resolved['origin'],
			'pad_id' => $resolved['pad_id'],
			'pad_url' => $resolved['pad_url'],
			'text' => $text,
			'snapshot_unavailable' => $snapshotUnavailable,
		];
	}

	/**
	 * The pad's current content as HTML, straight from the foreign server.
	 *
	 * Same address checks, same pinning, same size cap as the text export —
	 * only the format differs, and with it what may come back.
	 *
	 * @return array{origin:string,pad_id:string,pad_url:string,html:string}
	 */
	public function normalizeAndFetchExternalPublicPadHtml(string $padUrl): array {
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		$url = $this->buildPublicExportUrl($resolved['pad_url'], 'html');

		return [
			'origin' => $resolved['origin'],
			'pad_id' => $resolved['pad_id'],
			'pad_url' => $resolved['pad_url'],
			'html' => $this->sendPinnedPublicGetRequest(
				$url,
				$resolved['host'],
				$resolved['port'],
				$resolved['resolved_ips'],
				'html',
			),
		];
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

	private function buildPublicExportUrl(string $padUrl, string $format): string {
		$parsed = $this->parsePublicPadUrl($padUrl);
		return $parsed['pad_url'] . '/export/' . $format;
	}

	/**
	 * @param array{origin:string,pad_id:string,pad_url:string,host:string,port:int,resolved_ips:list<string>} $resolved
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

	/**
	 * @param list<string> $resolvedIps
	 */
	private function sendPinnedPublicGetRequest(
		string $url,
		string $host,
		int $port,
		array $resolvedIps,
		string $format = 'txt'
	): string {
		if (!function_exists('curl_init')) {
			throw new EtherpadClientException('External pad sync requires PHP cURL extension.');
		}

		$deadline = microtime(true) + (float)self::EXTERNAL_TOTAL_BUDGET_SECONDS;
		$errors = [];
		foreach ($resolvedIps as $ip) {
			$left = (int)floor($deadline - microtime(true));
			if ($left < self::EXTERNAL_MIN_ATTEMPT_SECONDS) {
				$errors[] = 'no time left to try ' . $ip;
				break;
			}
			$attemptTimeout = min($left, self::EXTERNAL_REQUEST_TIMEOUT_SECONDS);
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
				CURLOPT_CONNECTTIMEOUT => $attemptTimeout,
				CURLOPT_TIMEOUT => $attemptTimeout,
				CURLOPT_HTTPGET => true,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER => [
					'Accept: ' . self::acceptHeaderFor($format),
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
				// $sizeExceeded is flipped to true inside the CURLOPT_WRITEFUNCTION
				// closure above when the response exceeds the cap; Psalm doesn't
				// model curl invoking that callback, so it wrongly infers the flag
				// stays false here.
				/** @psalm-suppress TypeDoesNotContainType */
				if ($sizeExceeded) {
					throw new EtherpadClientException(
						'External public pad export exceeds maximum size (' . self::EXTERNAL_EXPORT_MAX_BYTES . ' bytes).'
					);
				}
				$errors[] = 'transport via ' . $ip . ': ' . ($curlError !== '' ? $curlError : 'unknown error');
				continue;
			}
			$this->assertSuccessfulExportStatus($httpCode);
			$this->assertAllowedExternalExportContentType($contentType, $format);
			return $buffer;
		}

		$detail = $errors !== [] ? implode('; ', $errors) : 'all resolved targets failed';
		throw new EtherpadClientException('Public export transport error: ' . $detail);
	}

	/**
	 * Only an answer that says it *is* the export.
	 *
	 * Redirects are not followed, but their bodies used to be taken
	 * anyway: a "Please sign in" page behind a 302 arrived as pad content.
	 * The text export happened to reject those on content type; the HTML
	 * export cannot, so the status is what decides.
	 */
	private function assertSuccessfulExportStatus(int $httpCode): void {
		if ($httpCode === 404) {
			throw new ExternalPadExportNotFoundException(
				'External public pad export was not found. Make sure the pad exists and can be exported.'
			);
		}
		if ($httpCode < 200 || $httpCode > 299) {
			throw new EtherpadClientException('Public export HTTP error (' . $httpCode . ')');
		}
	}

	/** What this app is willing to be sent back, per export format. */
	private static function acceptHeaderFor(string $format): string {
		return $format === 'html'
			? 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1'
			: 'text/plain, application/octet-stream;q=0.9, */*;q=0.1';
	}

	/**
	 * Only what this format asked for.
	 *
	 * The text export still refuses `text/html` — an error page answered
	 * with 200 is the shape that rule exists for, and reading one as pad
	 * content is exactly the confusion to avoid. The HTML export accepts it
	 * and nothing else: allowing the union for both would weaken the text
	 * path to buy nothing for this one.
	 */
	private function assertAllowedExternalExportContentType(string $contentTypeHeader, string $format = 'txt'): void {
		$raw = trim($contentTypeHeader);
		if ($raw === '') {
			throw new EtherpadClientException('Public export did not provide a Content-Type header.');
		}

		$normalized = strtolower(trim((string)explode(';', $raw, 2)[0]));

		if ($format === 'html') {
			if (in_array($normalized, ['text/html', 'application/xhtml+xml'], true)) {
				return;
			}
			throw new EtherpadClientException('Public HTML export returned unsupported Content-Type: ' . $normalized);
		}

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
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');
		$port = isset($parts['port']) ? $parts['port'] : 443;
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

		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');
		$port = isset($parts['port']) ? $parts['port'] : 443;
		$path = $parts['path'] ?? '';
		// `+` is literal in URL path segments — only query/form bodies treat
		// it as a space. Using urldecode here turned `/p/team+pad` into
		// pad-id `team pad`, then re-encoded to `/p/team%20pad` at fetch
		// time, hitting a different / non-existent remote pad.
		$decodedPath = rawurldecode($path);
		if ($scheme !== 'https' || $host === '' || $decodedPath === '' || $port <= 0 || $port > 65535) {
			throw new EtherpadClientException('Invalid public pad URL.');
		}

		if (preg_match('~^(.*)/p/([^/]+)$~', $decodedPath, $matches) !== 1) {
			throw new EtherpadClientException('Public pad URL must match /p/{padId}.');
		}

		$basePath = rtrim($matches[1], '/');
		$padId = trim($matches[2]);
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
