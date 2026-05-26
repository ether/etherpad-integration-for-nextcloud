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

class EtherpadHealthCheckService {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private IL10N $l10n,
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
			$template = (string)$this->l10n->t('Health check failed: {detail}');
			throw new AdminHealthCheckException(
				str_replace('{detail}', $detail, $template),
				0,
				$e,
			);
		}

		return new HealthCheckResult(
			$settings->etherpadHost,
			$settings->etherpadApiHost,
			$settings->etherpadApiVersion,
			(int)($result['pad_count'] ?? 0),
			(int)round(($this->now() - $startedAt) * 1000),
			rtrim($settings->etherpadApiHost, '/') . '/api/' . $settings->etherpadApiVersion . '/listAllPads',
			$this->pendingDeleteRetryService->countPendingDeletes(),
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

		// Transport-level — file_get_contents / curl wrapper failures.
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

	protected function now(): float {
		return microtime(true);
	}
}
