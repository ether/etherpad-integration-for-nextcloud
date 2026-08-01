<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\AdminHealthCheckException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IL10N;
use OCP\IURLGenerator;

class EtherpadHealthCheckService {
	private const REASON_API_KEY_MODE = 'api_key_mode';
	private const REASON_API_KEY_REJECTED = 'api_key_rejected';
	private const REASON_API_NOT_FOUND = 'api_not_found';
	private const REASON_SERVER_ERROR = 'server_error';
	private const REASON_DNS = 'dns';
	private const REASON_CONNECTION_REFUSED = 'connection_refused';
	private const REASON_TIMEOUT = 'timeout';
	private const REASON_TLS = 'tls';
	private const REASON_TRANSPORT = 'transport';
	private const REASON_INVALID_JSON = 'invalid_json';
	private const REASON_API_KEY_OTHER = 'api_key_other';
	private const REASON_UNKNOWN = '';

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private IL10N $l10n,
		private CookieDomainPolicy $cookieDomainPolicy,
		private BaseUrlReachabilityCheck $baseUrlCheck,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function check(ValidatedAdminSettings $settings): HealthCheckResult {
		$startedAt = $this->now();
		try {
			$result = $this->etherpadClient->healthCheck(
				$settings->etherpadApiHost,
				$settings->effectiveApiKey,
				$settings->etherpadApiVersion,
			);
		} catch (EtherpadClientException $e) {
			// EtherpadClient::apiCall wraps low-level transport failures in
			// a generic 'Etherpad API request failed: <method>' exception
			// with the real cause stored as previous. We surface both so
			// the user sees the actionable detail and the hint matcher has
			// the inner text to work with.
			$detail = $e->getMessage();
			$previous = $e->getPrevious();
			if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
				$detail .= ' (' . $previous->getMessage() . ')';
			}
			$reason = $this->classifyFailure($detail);
			$hint = $this->hintForReason($reason);
			if ($hint !== '') {
				$detail .= ' ' . $hint;
			}
			// We render the template ourselves and pass a plain string into
			// the exception instead of relying on IL10N's placeholder
			// substitution: the Exception constructor coerces non-strings
			// via __toString, but at least on Nextcloud 30 the L10NString
			// path leaks the literal '{detail}' through to consumers in
			// some catalog setups. Doing the substitution here removes that
			// surface area.
			$template = $this->l10n->t('Etherpad connection test failed: {detail}');
			throw new AdminHealthCheckException(
				str_replace('{detail}', $detail, $template),
				0,
				$e,
				$this->fieldForReason($reason, $settings),
			);
		}

		// The submitted settings, not the stored ones: the health check tests
		// the form as it stands, so a toggle the admin just flipped has to
		// count before it is saved.
		$cookieDomain = $settings->enableProtectedPads
			? $this->cookieDomainPolicy->decide(
				$this->urlGenerator->getBaseUrl(),
				$settings->etherpadHost,
				$this->cookieDomainPolicy->storedValue($settings->etherpadCookieDomain, $settings->cookieDomainConfigured),
			)
			: null;

		$padCount = (int)($result['pad_count'] ?? 0);
		$latencyMs = (int)round(($this->now() - $startedAt) * 1000.0);
		$target = rtrim($settings->etherpadApiHost, '/') . '/api/' . $settings->etherpadApiVersion . '/listAllPads';

		// Each part gets its own line, tied to the field it came from. The
		// protected-pads line is added by the caller from $cookieDomain, so the
		// summary and the payload cannot disagree about it. A single
		// verdict hid the fact that only the API host is contacted, so a typo in
		// the base URL — which every pad link in the browser uses — still read
		// as a clean pass. An empty API URL falls back to the base URL, so the
		// API line points at whichever field actually supplied the address.
		$apiField = $settings->etherpadApiHost === $settings->etherpadHost ? 'etherpad_host' : 'etherpad_api_host';
		$checks = [
			new HealthCheckItem(
				'api',
				HealthCheckItem::STATUS_OK,
				$this->l10n->t('Etherpad API reachable'),
				$this->fill(
					$this->l10n->t('{target} — {count} pads, {latency} ms'),
					['target' => $target, 'count' => (string)$padCount, 'latency' => (string)$latencyMs],
				),
				$apiField,
			),
			new HealthCheckItem(
				'api_key',
				HealthCheckItem::STATUS_OK,
				$this->l10n->t('API key accepted'),
				$this->fill($this->l10n->t('API version {version}'), ['version' => $settings->etherpadApiVersion]),
				'etherpad_api_key',
			),
			$this->baseUrlCheck->check($settings->etherpadHost),
		];

		return new HealthCheckResult(
			$settings->etherpadHost,
			$settings->etherpadApiHost,
			$settings->etherpadApiVersion,
			$padCount,
			$latencyMs,
			$target,
			$this->pendingDeleteRetryService->countPendingDeletes(),
			$cookieDomain,
			$checks,
		);
	}

	/**
	 * Classify a failure once, then derive both the hint and the field from
	 * the result. Two separate matchers over the same strings could hand out
	 * a correct hint with the wrong field.
	 *
	 * Matching is on substrings rather than exception subtypes because the
	 * upstream library bundles many failure shapes into the same message. If
	 * upstream wording changes the classification just falls through to
	 * REASON_UNKNOWN; the error itself still surfaces.
	 */
	private function classifyFailure(string $rawMessage): string {
		$message = strtolower($rawMessage);

		if (str_contains($message, 'no or wrong api key')
			|| str_contains($message, 'wrong api key')
			|| str_contains($message, 'invalid apikey')) {
			return self::REASON_API_KEY_MODE;
		}

		// HTTP statuses come before transport reasons because they distinguish
		// "Etherpad reachable but unhappy" from "cannot reach Etherpad at all".
		if (str_contains($message, 'http error (401)') || str_contains($message, 'http error (403)')) {
			return self::REASON_API_KEY_REJECTED;
		}
		if (str_contains($message, 'http error (404)')) {
			return self::REASON_API_NOT_FOUND;
		}
		if (preg_match('/http error \(5\d{2}\)/', $message) === 1) {
			return self::REASON_SERVER_ERROR;
		}

		// Transport level — Nextcloud HTTP client (IClientService) failures.
		if (str_contains($message, 'transport error')) {
			if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') || str_contains($message, 'could not resolve host')) {
				return self::REASON_DNS;
			}
			if (str_contains($message, 'connection refused')) {
				return self::REASON_CONNECTION_REFUSED;
			}
			if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
				return self::REASON_TIMEOUT;
			}
			if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
				return self::REASON_TLS;
			}
			return self::REASON_TRANSPORT;
		}

		if (str_contains($message, 'invalid json response')) {
			return self::REASON_INVALID_JSON;
		}

		// Last resort, so it never shadows a more specific reason and its
		// hint: anything else that mentions the key is still about the key,
		// even when we have nothing to advise. Without this a message like
		// "API key file could not be read" would mark no field at all.
		if (str_contains($message, 'api key') || str_contains($message, 'apikey')) {
			return self::REASON_API_KEY_OTHER;
		}

		return self::REASON_UNKNOWN;
	}

	/** Empty when no hint applies — the bare error stays in the detail field. */
	private function hintForReason(string $reason): string {
		return match ($reason) {
			self::REASON_API_KEY_MODE => $this->l10n->t('Hint: Set "authenticationMethod": "apikey" in Etherpad\'s settings.json.'),
			self::REASON_API_KEY_REJECTED => $this->l10n->t('Hint: Etherpad rejected the API key. Check that the key matches Etherpad\'s APIKEY.txt.'),
			self::REASON_API_NOT_FOUND => $this->l10n->t('Hint: API endpoint not found. Check the API host and that the configured API version is supported by your Etherpad.'),
			self::REASON_SERVER_ERROR => $this->l10n->t('Hint: Etherpad returned a server error. Check the Etherpad server logs.'),
			self::REASON_DNS => $this->l10n->t('Hint: The configured Etherpad host did not resolve. Check the hostname for typos and that DNS reaches it from this server.'),
			self::REASON_CONNECTION_REFUSED => $this->l10n->t('Hint: Connection refused. Etherpad does not appear to be running on the configured host and port.'),
			self::REASON_TIMEOUT => $this->l10n->t('Hint: Connection timed out. Check that this server can reach the Etherpad host (firewall, network).'),
			self::REASON_TLS => $this->l10n->t('Hint: TLS handshake failed. Check the Etherpad certificate and that the configured URL uses the right scheme.'),
			self::REASON_TRANSPORT => $this->l10n->t('Hint: Could not reach Etherpad. Check the API host and that this server can connect to it.'),
			self::REASON_INVALID_JSON => $this->l10n->t('Hint: Etherpad returned non-JSON. Likely a reverse proxy or HTML error page in front of the API host.'),
			default => '',
		};
	}

	/**
	 * Which input the failure points at. A rejected key is about the key; an
	 * address that does not resolve, refuses or 404s is about the address —
	 * which is the base URL field when no separate API URL is set. A server
	 * error or non-JSON answer means the address is fine and something behind
	 * it is not, so neither field is marked.
	 */
	private function fieldForReason(string $reason, ValidatedAdminSettings $settings): string {
		$apiField = $settings->etherpadApiHost === $settings->etherpadHost ? 'etherpad_host' : 'etherpad_api_host';

		return match ($reason) {
			self::REASON_API_KEY_MODE,
			self::REASON_API_KEY_REJECTED,
			self::REASON_API_KEY_OTHER => 'etherpad_api_key',
			self::REASON_API_NOT_FOUND,
			self::REASON_DNS,
			self::REASON_CONNECTION_REFUSED,
			self::REASON_TIMEOUT,
			self::REASON_TLS,
			self::REASON_TRANSPORT => $apiField,
			default => '',
		};
	}

	/** @param array<string,string> $parameters */
	private function fill(string $text, array $parameters): string {
		foreach ($parameters as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}

	protected function now(): float {
		return microtime(true);
	}
}
