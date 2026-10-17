<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Exception\ExternalPadHttpErrorException;
use OCA\EtherpadNextcloud\Http\ExportAttemptSink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

class ExternalPadExportFetcher {
	private const EXTERNAL_EXPORT_MAX_BYTES = 5242880; // 5 MiB
	private const EXTERNAL_REQUEST_TIMEOUT_SECONDS = 15;

	/**
	 * The whole fetch, the name lookup included: an attempt gets what is
	 * left, and one that no longer fits is not made. The budget cannot
	 * bound the lookup itself: `dns_get_record()` takes no timeout, so a
	 * dead nameserver holds a request for the resolver's own retries.
	 */
	private const EXTERNAL_TOTAL_BUDGET_SECONDS = 20;
	private const EXTERNAL_MIN_ATTEMPT_SECONDS = 2;

	/**
	 * IPv4 blocks that are not public: those IANA's special-purpose
	 * registry marks as not globally reachable (checked 2026-10-17), and
	 * multicast. A block counts as a whole, here and for IPv6: the
	 * reachable addresses inside one belong to protocol services such as
	 * anycast relays, not to web servers.
	 *
	 * @see https://www.iana.org/assignments/iana-ipv4-special-registry/
	 */
	private const NOT_PUBLIC_IPV4 = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10', // shared address space: carrier-grade NAT, Tailscale
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.2/32',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4', // multicast
		'240.0.0.0/4', // reserved, the limited broadcast address with it
	];

	/**
	 * Apart from NAT64 and 6to4, which count as the IPv4 address they
	 * carry, IPv6 is public only in global unicast, `2000::/3`, and outside
	 * these blocks IANA's special-purpose registry marks as not globally
	 * reachable (checked 2026-10-17). The other addresses that are not
	 * public - loopback, IPv4-mapped and IPv4-compatible, unique-local,
	 * link-local, site-local, multicast - lie outside `2000::/3`.
	 *
	 * @see https://www.iana.org/assignments/iana-ipv6-special-registry/
	 */
	private const NOT_PUBLIC_IPV6 = [
		'2001::/23', // IETF protocol assignments, Teredo among them
		'2001:db8::/32',
		'3fff::/20',
	];

	public function __construct(
		private IConfig $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{origin:string,pad_id:string,pad_url:string,text:string} */
	public function normalizeAndFetchExternalPublicPadText(string $padUrl): array {
		$deadline = $this->startBudget();
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		return [
			'origin' => $resolved['origin'],
			'pad_id' => $resolved['pad_id'],
			'pad_url' => $resolved['pad_url'],
			'text' => $this->fetchPublicExport($resolved, 'txt', $deadline),
		];
	}

	/**
	 * @return array{origin:string,pad_id:string,pad_url:string,text:string,snapshot_unavailable:bool}
	 */
	public function normalizeAndFetchExternalPublicPadTextOrEmpty(string $padUrl): array {
		$deadline = $this->startBudget();
		$resolved = $this->resolveAndValidateExternalPublicPadUrl($padUrl);
		$snapshotUnavailable = false;
		try {
			$text = $this->fetchPublicExport($resolved, 'txt', $deadline);
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
	 * Same address checks, pinning and size cap as the text export — only
	 * the format differs, and with it what may come back.
	 */
	public function fetchExternalPublicPadHtml(string $padUrl): string {
		$deadline = $this->startBudget();

		return $this->fetchPublicExport($this->resolveAndValidateExternalPublicPadUrl($padUrl), 'html', $deadline);
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
	 * @param array{origin:string,pad_id:string,pad_url:string,port:int,resolved_ips:list<string>} $resolved
	 */
	private function fetchPublicExport(array $resolved, string $format, float $deadline): string {
		return $this->sendPinnedPublicGetRequest(
			$this->buildPublicExportUrl($resolved['pad_url'], $format),
			$resolved['port'],
			$resolved['resolved_ips'],
			$format,
			$deadline,
		);
	}

	private function startBudget(): float {
		return $this->nowSeconds() + (float)self::EXTERNAL_TOTAL_BUDGET_SECONDS;
	}

	/** The budget's clock, sub-second, through the same factory as the rest. */
	private function nowSeconds(): float {
		return (float)$this->timeFactory->now()->format('U.u');
	}

	/** @return array{origin:string,pad_id:string,pad_url:string,port:int,resolved_ips:list<string>} */
	private function resolveAndValidateExternalPublicPadUrl(string $padUrl): array {
		$parsed = $this->parsePublicPadUrl($padUrl);
		$padId = $parsed['pad_id'];
		if (preg_match('/^g\.[^$]+\$.+$/', $padId) === 1) {
			throw new ExternalPadException('Only public pad URLs can be linked from external servers.', ExternalPadException::NOT_PUBLIC);
		}

		return [
			'origin' => $parsed['origin'],
			'pad_id' => $parsed['pad_id'],
			'pad_url' => $parsed['pad_url'],
			'port' => $parsed['port'],
			'resolved_ips' => $this->resolveAndValidateExternalHost($parsed['host'], $parsed['origin']),
		];
	}

	/**
	 * @param list<string> $resolvedIps
	 */
	private function sendPinnedPublicGetRequest(
		string $url,
		int $port,
		array $resolvedIps,
		string $format,
		float $deadline
	): string {
		if (!function_exists('curl_init')) {
			throw new ExternalPadException('External pad sync requires PHP cURL extension.', ExternalPadException::NO_CURL);
		}

		$errors = [];
		$curlErrors = [];
		foreach ($resolvedIps as $ip) {
			$left = (int)floor($deadline - $this->nowSeconds());
			if ($left < self::EXTERNAL_MIN_ATTEMPT_SECONDS) {
				$errors[] = 'no time left to try ' . $ip;
				break;
			}
			$attemptTimeout = min($left, self::EXTERNAL_REQUEST_TIMEOUT_SECONDS);
			$sink = new ExportAttemptSink(self::EXTERNAL_EXPORT_MAX_BYTES);
			$result = $this->perform($url, self::attemptOptions($port, $ip, $format, $attemptTimeout, $sink));
			$curlErrors[] = $result['errno'];

			if (!$result['completed']) {
				if ($sink->tooLarge()) {
					// Same type as the own-server cap, so both answer the
					// reader with "too large to show" instead of one of them
					// looking like an outage.
					throw new EtherpadTooLargeException(
						'External public pad export exceeds maximum size (' . self::EXTERNAL_EXPORT_MAX_BYTES . ' bytes).'
					);
				}
				$errors[] = 'transport via ' . $ip . ': ' . ($result['error'] !== '' ? $result['error'] : 'unknown error');
				continue;
			}
			$this->assertSuccessfulExportStatus($result['status']);
			$this->assertAllowedExternalExportContentType($sink->contentType(), $format);
			return $sink->body();
		}

		$detail = $errors !== [] ? implode('; ', $errors) : 'all resolved targets failed';
		throw new ExternalPadException('Public export transport error: ' . $detail, self::transportReason($curlErrors));
	}

	/**
	 * The address is pinned with CONNECT_TO, not RESOLVE: a proxy cURL takes
	 * from the environment is then asked for the vetted address, where an
	 * HTTP, socks4a or socks5h proxy asked for the name would resolve it
	 * again. SNI and the certificate check still use the URL's host. The
	 * entry leaves host and port empty, so it matches whatever form cURL
	 * gives the host - punycode, decoded percent signs, a normalised IP
	 * address - where an entry naming the host would be skipped silently.
	 * The handle makes one request, so the entry pins only that one. An
	 * IPv6 address goes in brackets, or cURL reads part of it as the host.
	 *
	 * @return array<int, mixed>
	 */
	private static function attemptOptions(int $port, string $ip, string $format, int $timeout, ExportAttemptSink $sink): array {
		return [
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPGET => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => [
				'Accept: ' . self::acceptHeaderFor($format),
			],
			CURLOPT_CONNECT_TO => ['::' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port],
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_HEADERFUNCTION => $sink->header(...),
			CURLOPT_WRITEFUNCTION => $sink->chunk(...),
		];
	}

	/**
	 * One request through cURL: the one place the fetcher reaches another
	 * server, so a test can answer in its place.
	 *
	 * @param array<int, mixed> $options
	 * @return array{completed: bool, status: int, error: string, errno: int}
	 */
	protected function perform(string $url, array $options): array {
		$curl = curl_init($url);
		if ($curl === false) {
			throw new ExternalPadException('Could not initialize external export request.', ExternalPadException::UNREACHABLE);
		}
		// curl_setopt_array() stops at the first option cURL refuses and
		// sets none after it: the pin or the sink could be missing, so the
		// request would go wherever the name resolves now, or its body to
		// the output, whole.
		if (!curl_setopt_array($curl, $options)) {
			throw new ExternalPadException('Could not set up external export request.', ExternalPadException::UNREACHABLE);
		}
		$completed = curl_exec($curl) !== false;
		return [
			'completed' => $completed,
			'status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
			'error' => curl_error($curl),
			'errno' => curl_errno($curl),
		];
	}

	/**
	 * Why no attempt got an answer: a certificate this instance does not
	 * trust, which a later try does not mend, or the server not reached.
	 * cURL reports a certificate that failed verification as 60, and a
	 * name it does not match as 51 before libcurl 7.62.0.
	 *
	 * One certificate failure decides it, whatever the other addresses did:
	 * an instance without an IPv6 route fails the IPv6 attempt on every
	 * try, with the code cURL also gives a connection refused for a moment.
	 * A name whose addresses show different certificates, the trusted one
	 * briefly down, gets the certificate's sentence too; opening the file
	 * again tries again.
	 *
	 * @param list<int> $curlErrors
	 * @return ExternalPadException::UNTRUSTED_CERTIFICATE|ExternalPadException::UNREACHABLE
	 */
	private static function transportReason(array $curlErrors): string {
		return array_intersect($curlErrors, [51, 60]) !== []
			? ExternalPadException::UNTRUSTED_CERTIFICATE
			: ExternalPadException::UNREACHABLE;
	}

	/**
	 * Only an answer that says it *is* the export. A redirect's body is
	 * never taken: behind a 302 there is usually a sign-in page, which the
	 * HTML export could not tell from a pad. A redirect gets its own
	 * reason, as it usually means a pad behind a login.
	 */
	private function assertSuccessfulExportStatus(int $httpCode): void {
		if ($httpCode === 404) {
			throw new ExternalPadExportNotFoundException(
				'External public pad export was not found. Make sure the pad exists and can be exported.'
			);
		}
		if ($httpCode >= 300 && $httpCode <= 399) {
			throw new ExternalPadException('Public export redirected (' . $httpCode . ')', ExternalPadException::REDIRECTED);
		}
		if ($httpCode < 200 || $httpCode > 299) {
			throw new ExternalPadHttpErrorException('Public export HTTP error (' . $httpCode . ')', $httpCode);
		}
	}

	private static function acceptHeaderFor(string $format): string {
		return $format === 'html'
			? 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1'
			: 'text/plain, application/octet-stream;q=0.9, */*;q=0.1';
	}

	/**
	 * Only what this format asked for. The text export refuses `text/html`:
	 * that is how an error page answered with 200 looks.
	 */
	private function assertAllowedExternalExportContentType(string $contentTypeHeader, string $format = 'txt'): void {
		$raw = trim($contentTypeHeader);
		if ($raw === '') {
			throw new ExternalPadException('Public export did not provide a Content-Type header.', ExternalPadException::UNEXPECTED_ANSWER);
		}

		$normalized = strtolower(trim((string)explode(';', $raw, 2)[0]));

		if ($format === 'html') {
			if (in_array($normalized, ['text/html', 'application/xhtml+xml'], true)) {
				return;
			}
			throw new ExternalPadException('Public HTML export returned unsupported Content-Type: ' . $normalized, ExternalPadException::UNEXPECTED_ANSWER);
		}

		if ($normalized === 'text/html') {
			throw new ExternalPadException('Public export returned unsupported Content-Type: text/html', ExternalPadException::UNEXPECTED_ANSWER);
		}
		if (str_starts_with($normalized, 'text/')) {
			return;
		}
		if (in_array($normalized, ['application/octet-stream', 'application/text'], true)) {
			return;
		}

		throw new ExternalPadException('Public export returned unsupported Content-Type: ' . $normalized, ExternalPadException::UNEXPECTED_ANSWER);
	}

	/** @return list<string> */
	private function resolveAndValidateExternalHost(string $host, string $origin): array {
		if ((string)$this->config->getAppValue('etherpad_nextcloud', 'allow_external_pads', 'no') !== 'yes') {
			throw new ExternalPadException('External pad linking is disabled by admin settings.', ExternalPadException::DISABLED);
		}
		if (!$this->isAllowlistedExternalHost($host, $origin)) {
			throw new ExternalPadException('External pad host is not in the allowlist.', ExternalPadException::NOT_ALLOWED);
		}
		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
			throw new ExternalPadException('Local hosts are not allowed for external pad sync.', ExternalPadException::LOCAL_ADDRESS);
		}

		// A URL writes an IPv6 address in brackets, which parse_url() keeps.
		$literal = str_starts_with($host, '[') ? substr($host, 1, -1) : $host;
		if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
			if (!$this->isPublicIp($literal)) {
				throw new ExternalPadException('External pad host is an address that is not public: ' . $literal, ExternalPadException::LOCAL_ADDRESS);
			}
			return [$literal];
		}

		$records = $this->lookUp($host);
		if ($records === []) {
			throw new ExternalPadException('Could not resolve external pad host.', ExternalPadException::UNRESOLVED);
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
			throw new ExternalPadException('Could not resolve external pad host to IP.', ExternalPadException::UNRESOLVED);
		}

		foreach ($resolvedIps as $ip) {
			if (!$this->isPublicIp($ip)) {
				throw new ExternalPadException('External pad host resolves to an address that is not public: ' . $ip, ExternalPadException::LOCAL_ADDRESS);
			}
		}

		return array_values(array_unique($resolvedIps));
	}

	/**
	 * The A and AAAA records of a name, none if it has none or the lookup
	 * failed. The one place the fetcher asks the resolver, so a test can
	 * answer in its place instead of the network.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	protected function lookUp(string $host): array {
		$records = @dns_get_record($host, DNS_A + DNS_AAAA);
		return is_array($records) ? $records : [];
	}

	/**
	 * Whether an address is public is decided on the address as the system
	 * parses it, by the lists above. PHP's filter judges an address by how
	 * it is written, and its blocks change between PHP versions.
	 */
	private function isPublicIp(string $ip): bool {
		$address = inet_pton($ip);
		return $address !== false && self::isPublicAddress($address);
	}

	/** @param string $address packed, as inet_pton() returns it */
	private static function isPublicAddress(string $address): bool {
		if (strlen($address) === 4) {
			return !self::inAnyBlock($address, self::NOT_PUBLIC_IPV4);
		}
		// A connection to a NAT64 or a 6to4 address ends at the IPv4
		// address it carries. RFC 6052 tells translators to drop a NAT64
		// packet for an address that is not public, but not every one does.
		if (self::inBlock($address, '64:ff9b::/96')) {
			return self::isPublicAddress(substr($address, 12, 4));
		}
		if (self::inBlock($address, '2002::/16')) {
			return self::isPublicAddress(substr($address, 2, 4));
		}
		return self::inBlock($address, '2000::/3') && !self::inAnyBlock($address, self::NOT_PUBLIC_IPV6);
	}

	/** @param list<string> $blocks */
	private static function inAnyBlock(string $address, array $blocks): bool {
		foreach ($blocks as $block) {
			if (self::inBlock($address, $block)) {
				return true;
			}
		}
		return false;
	}

	/** @param string $block an address and a prefix length, `10.0.0.0/8` */
	private static function inBlock(string $address, string $block): bool {
		[$network, $bits] = explode('/', $block);
		$prefix = (string)inet_pton($network);
		$whole = intdiv((int)$bits, 8);
		$rest = (int)$bits % 8;
		return strncmp($address, $prefix, $whole) === 0
			&& ($rest === 0 || (ord($address[$whole]) ^ ord($prefix[$whole])) >> (8 - $rest) === 0);
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
			throw new ExternalPadException('Invalid public pad URL.', ExternalPadException::INVALID_URL);
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts)) {
			throw new ExternalPadException('Invalid public pad URL.', ExternalPadException::INVALID_URL);
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new ExternalPadException('Public pad URL must not contain credentials.', ExternalPadException::CREDENTIALS_IN_URL);
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');
		$port = isset($parts['port']) ? $parts['port'] : 443;
		$path = $parts['path'] ?? '';
		// `+` is literal in a URL path; only form bodies read it as a space.
		// urldecode() would turn `/p/team+pad` into another pad's name.
		$decodedPath = rawurldecode($path);
		// parse_url() takes anything in brackets as the host; only an IPv6
		// address belongs there, and cURL refuses the rest.
		if ($scheme !== 'https' || $host === '' || $port <= 0 || $port > 65535
			|| (str_starts_with($host, '[') && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
			|| preg_match('/[\x00-\x1F\x7F]/', $decodedPath) === 1) {
			throw new ExternalPadException('Invalid public pad URL.', ExternalPadException::INVALID_URL);
		}

		if (preg_match('~^(.*)/p/([^/]+)$~', $decodedPath, $matches) !== 1) {
			throw new ExternalPadException('Public pad URL must match /p/{padId}.', ExternalPadException::NOT_A_PAD_URL);
		}

		$basePath = rtrim($matches[1], '/');
		$padId = trim($matches[2]);
		if ($padId === '') {
			throw new ExternalPadException('Public pad URL names no pad after /p/.', ExternalPadException::NOT_A_PAD_URL);
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
