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
	public function __construct(
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private IL10N $l10n,
		private CookieDomainPolicy $cookieDomainPolicy,
		private CookieDomainMessages $cookieDomainMessages,
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
			$hint = $this->hintForFailureMessage($detail);
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
				$this->fieldForFailureMessage($detail, $settings),
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

		// Each part gets its own line, tied to the field it came from. A single
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
			$this->baseUrlCheck->check($settings->etherpadHost, $settings->etherpadApiHost),
			$this->cookieDomainMessages->asCheckItem($cookieDomain),
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
	 * Map the full failure message (including inner-exception text) onto an
	 * actionable hint string. Returns an empty string when no hint applies —
	 * the bare error stays in the detail field in that case.
	 *
	 * Matching is intentionally on substrings rather than exception subtypes
	 * because the upstream library bundles many failure shapes into the same
	 * message. If upstream wording changes the hint just drops; the error
	 * itself still surfaces.
	 */
	private function hintForFailureMessage(string $rawMessage): string {
		$message = strtolower($rawMessage);

		if (str_contains($message, 'no or wrong api key')
			|| str_contains($message, 'wrong api key')
			|| str_contains($message, 'invalid apikey')) {
			return $this->l10n->t('Hint: Set "authenticationMethod": "apikey" in Etherpad\'s settings.json.');
		}

		// HTTP status hints come before transport hints because they
		// distinguish "Etherpad reachable but unhappy" from "can't reach
		// Etherpad at all".
		if (str_contains($message, 'http error (401)') || str_contains($message, 'http error (403)')) {
			return $this->l10n->t('Hint: Etherpad rejected the API key. Check that the key matches Etherpad\'s APIKEY.txt.');
		}
		if (str_contains($message, 'http error (404)')) {
			return $this->l10n->t('Hint: API endpoint not found. Check the API host and that the configured API version is supported by your Etherpad.');
		}
		if (preg_match('/http error \(5\d{2}\)/', $message) === 1) {
			return $this->l10n->t('Hint: Etherpad returned a server error. Check the Etherpad server logs.');
		}

		// Transport-level — Nextcloud HTTP client (IClientService) connection failures.
		if (str_contains($message, 'transport error')) {
			if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') || str_contains($message, 'could not resolve host')) {
				return $this->l10n->t('Hint: The configured Etherpad host did not resolve. Check the hostname for typos and that DNS reaches it from this server.');
			}
			if (str_contains($message, 'connection refused')) {
				return $this->l10n->t('Hint: Connection refused. Etherpad does not appear to be running on the configured host and port.');
			}
			if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
				return $this->l10n->t('Hint: Connection timed out. Check that this server can reach the Etherpad host (firewall, network).');
			}
			if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
				return $this->l10n->t('Hint: TLS handshake failed. Check the Etherpad certificate and that the configured URL uses the right scheme.');
			}
			return $this->l10n->t('Hint: Could not reach Etherpad. Check the API host and that this server can connect to it.');
		}

		if (str_contains($message, 'invalid json response')) {
			return $this->l10n->t('Hint: Etherpad returned non-JSON. Likely a reverse proxy or HTML error page in front of the API host.');
		}

		return '';
	}

	/** @param array<string,string> $parameters */
	private function fill(string $text, array $parameters): string {
		foreach ($parameters as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}

	/**
	 * Which input the failure points at. A rejected key is about the key; a
	 * host that does not resolve, refuses or 404s is about the address — which
	 * is the base URL field when no separate API URL is set.
	 */
	private function fieldForFailureMessage(string $rawMessage, ValidatedAdminSettings $settings): string {
		$message = strtolower($rawMessage);
		$apiField = $settings->etherpadApiHost === $settings->etherpadHost ? 'etherpad_host' : 'etherpad_api_host';

		if (str_contains($message, 'api key')
			|| str_contains($message, 'apikey')
			|| str_contains($message, 'http error (401)')
			|| str_contains($message, 'http error (403)')) {
			return 'etherpad_api_key';
		}
		if (str_contains($message, 'transport error') || str_contains($message, 'http error (404)')) {
			return $apiField;
		}
		return '';
	}

	protected function now(): float {
		return microtime(true);
	}
}
