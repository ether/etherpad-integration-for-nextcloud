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
	private const DETAIL_MAX_LENGTH = 160;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private PendingDeleteRetryService $pendingDeleteRetryService,
		private IL10N $l10n,
		private CookieDomainPolicy $cookieDomainPolicy,
		private BaseUrlReachabilityCheck $baseUrlCheck,
		private IURLGenerator $urlGenerator,
		private EtherpadReleasePolicy $releasePolicy,
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
			// Classify on the full text: a keyword can sit behind the cut, and
			// losing it would drop both the hint and the field. Only what the
			// admin reads is shortened — a transport failure can carry a long
			// tail of internal hostnames and addresses.
			$reason = $this->classifyFailure($detail);
			$detail = $this->shorten($detail);
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
			$this->sessionCookieCheck($settings),
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
	 * What the Etherpad session cookie will look like, and why.
	 *
	 * The one place an admin can see it. The release decides whether the
	 * cookie may be kept from JavaScript, it is discovered rather than
	 * configured, and it lives in an app value with no field of its own —
	 * so when the detection is wrong, the symptom is that no protected pad
	 * opens and nothing anywhere says why.
	 *
	 * It reads the answer the open path is using, and asks the host being
	 * submitted separately. Those two can disagree — a cached release from
	 * before a downgrade against a server that has since gone back to 2.x
	 * — and that disagreement *is* the lockout, so it is what gets said.
	 *
	 * Read, not resolved: calling the policy's own predicate would refresh
	 * an expired cache, which means probing the *stored* host and writing
	 * three app values. Testing an unsaved form would teach the open path
	 * something, and against a host that is gone it would also park real
	 * pad opens in the failure backoff because somebody pressed a button.
	 *
	 * Everything lands on `etherpad_session_cookie`. Sharing a field with
	 * the API lines means losing to them: the panel keeps the highest
	 * severity per field, and a passing `api` outranks a skip.
	 */
	private function sessionCookieCheck(ValidatedAdminSettings $settings): HealthCheckItem {
		if (!$settings->enableProtectedPads) {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_SKIPPED,
				$this->l10n->t('Session cookie: not checked, protected pads are off'),
			);
		}

		$unrecognised = $this->releasePolicy->unrecognisedOverride();
		if ($unrecognised !== '') {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_WARNING,
				$this->l10n->t('Etherpad session cookie'),
				$this->fill(
					$this->l10n->t('"{value}" is not one of auto, yes or no, so it is being ignored and the release decides. Set one of those three, or remove the setting.'),
					['value' => $unrecognised],
				),
			);
		}

		try {
			// As patient as the calls beside it: a slow but healthy pad
			// server is not a misconfiguration of this app, and this is not
			// the open path.
			$release = $this->etherpadClient->detectReleaseVersion(
				$settings->etherpadApiHost,
				EtherpadClient::REQUEST_TIMEOUT_SECONDS,
			);
		} catch (\Throwable $e) {
			$release = '';
		}

		$override = $this->releasePolicy->overrideMode();
		if ($override !== EtherpadReleasePolicy::OVERRIDE_AUTO) {
			return $this->overriddenSessionCookieItem($override, $release);
		}

		if ($release === '') {
			// Not a warning. Nothing is broken: the cookie stays readable,
			// which is what every Etherpad before 3.0 needs anyway. A pad
			// server without /health, or a proxy that routes /api and not
			// /health, works and merely misses a hardening.
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_SKIPPED,
				$this->l10n->t('Session cookie: readable by scripts'),
				$this->l10n->t('The Etherpad release could not be read from /health, so the cookie is left readable.'),
			);
		}

		$knownRelease = $this->releasePolicy->knownRelease();
		if ($knownRelease === '') {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_OK,
				$this->fill(
					$this->l10n->t('Session cookie: Etherpad {release}, checked on the first protected pad open'),
					['release' => $this->shorten($release)],
				),
			);
		}

		$sending = EtherpadReleasePolicy::allowsHttpOnly($knownRelease);
		if ($sending !== EtherpadReleasePolicy::allowsHttpOnly($release)) {
			// The cache and the server disagree, which is precisely the
			// lockout an admin comes here to explain.
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_WARNING,
				$this->l10n->t('Etherpad session cookie'),
				$this->fill(
					$sending
						? $this->l10n->t('Pads are being sent an HttpOnly cookie, from Etherpad {known} seen earlier, but this server reports {release}, which reads the cookie in the browser. Protected pads will not open until that is checked again.')
						: $this->l10n->t('This server reports Etherpad {release}, which reads the session server-side, but pads are still being sent a script-readable cookie from Etherpad {known} seen earlier.'),
					['release' => $this->shorten($release), 'known' => $this->shorten($knownRelease)],
				),
			);
		}

		return $this->sessionCookieItem(
			HealthCheckItem::STATUS_OK,
			$this->fill(
				$sending
					? $this->l10n->t('Session cookie kept from scripts (Etherpad {release})')
					: $this->l10n->t('Session cookie readable by scripts (Etherpad {release})'),
				['release' => $this->shorten($knownRelease)],
			),
		);
	}

	/**
	 * A hand-set flag, reported by what it costs rather than by the fact
	 * that it is set.
	 *
	 * The two values are not symmetric. `yes` below Etherpad 3.0 stops every
	 * protected pad from opening and has to shout. `no` gives up a hardening
	 * and is what this app did before any of it — warning on that teaches an
	 * admin to ignore the line that would have told them about the other.
	 *
	 * Either way it says what the release is, so somebody who reached for
	 * the escape hatch during an outage can see whether they may put it back.
	 */
	private function overriddenSessionCookieItem(string $override, string $release): HealthCheckItem {
		$forcedOn = $override === EtherpadReleasePolicy::OVERRIDE_YES;
		$serverReads = $release === ''
			? $this->l10n->t('The pad server did not report its release.')
			: $this->fill(
				EtherpadReleasePolicy::allowsHttpOnly($release)
					? $this->l10n->t('This server reports Etherpad {release}, which reads the session server-side — automatic detection would set the same thing.')
					: $this->l10n->t('This server reports Etherpad {release}, which reads the session in the browser.'),
				['release' => $this->shorten($release)],
			);

		if (!$forcedOn) {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_OK,
				$this->l10n->t('Session cookie readable by scripts (set by hand)'),
				$serverReads,
			);
		}

		return $this->sessionCookieItem(
			HealthCheckItem::STATUS_WARNING,
			$this->l10n->t('Etherpad session cookie'),
			$this->l10n->t('HttpOnly is switched on by hand. An Etherpad below 3.0 cannot read the cookie, and no protected pad will open.') . ' ' . $serverReads,
		);
	}

	private function sessionCookieItem(string $status, string $label, string $detail = ''): HealthCheckItem {
		return new HealthCheckItem('session_cookie', $status, $label, $detail, 'etherpad_session_cookie');
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

	/** Same cap as BaseUrlReachabilityCheck; these end up in the same panel. */
	private function shorten(string $message): string {
		$message = trim($message);
		return strlen($message) > self::DETAIL_MAX_LENGTH
			? substr($message, 0, self::DETAIL_MAX_LENGTH) . '…'
			: $message;
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
