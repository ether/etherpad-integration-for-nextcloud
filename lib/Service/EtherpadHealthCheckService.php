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
use OCA\EtherpadNextcloud\Util\DiagnosticText;
use OCP\AppFramework\Utility\ITimeFactory;
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
	/**
	 * Named rather than empty: the reason is the only machine-readable
	 * record of a failure, and '' is what a thrower that passed none
	 * would log.
	 */
	private const REASON_UNKNOWN = 'unknown';

	public function __construct(
		private EtherpadClient $etherpadClient,
		private BindingService $bindingService,
		private IL10N $l10n,
		private CookieDomainPolicy $cookieDomainPolicy,
		private BaseUrlReachabilityCheck $baseUrlCheck,
		private IURLGenerator $urlGenerator,
		private EtherpadReleasePolicy $releasePolicy,
		private PadSessionService $padSessionService,
		private TrustedEmbedOriginsNormalizer $trustedEmbedOriginsNormalizer,
		private ITimeFactory $timeFactory,
	) {
	}

	public function check(ValidatedAdminSettings $settings): HealthCheckResult {
		$startedAt = $this->nowSeconds();
		try {
			$this->etherpadClient->assertApiKeyAccepted(
				$settings->etherpadApiHost,
				$settings->effectiveApiKey(),
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
			// Redacted before it is cut, or a key straddling the cut survives
			// as a prefix, and before the hint is appended, which is
			// translated and would make the log follow the admin's language.
			$detail = DiagnosticText::withoutSecret($detail, $settings->effectiveApiKey()->reveal());
			$detail = DiagnosticText::shorten($detail);
			$cause = $detail;
			$hint = $this->hintForReason($reason);
			if ($hint !== '') {
				$detail .= ' ' . $hint;
			}
			// Filled after translating: IL10N fills parameters with vsprintf(),
			// which knows no {name}.
			$template = $this->l10n->t('Etherpad connection test failed: {detail}');
			throw new AdminHealthCheckException(
				str_replace('{detail}', $detail, $template),
				0,
				$e,
				$this->fieldForReason($reason, $settings),
				$reason,
				$cause,
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

		$latencyMs = (int)round(($this->nowSeconds() - $startedAt) * 1000.0);
		$target = EtherpadClient::buildApiUrl(
			$settings->etherpadApiHost,
			$settings->etherpadApiVersion,
			EtherpadClient::API_KEY_PROBE_METHOD,
		);

		// Each part gets its own line, tied to the field it came from. The
		// protected-pads line is added by the caller from $cookieDomain, so the
		// summary and the payload cannot disagree about it. A single
		// verdict hid the fact that only the API host is contacted, so a typo in
		// the base URL — which every pad link in the browser uses — still read
		// as a clean pass. An empty API URL falls back to the base URL, so the
		// API line points at whichever field actually supplied the address.
		$apiField = ValidatedAdminSettings::isOwnApiHost($settings->etherpadApiHost, $settings->etherpadHost) ? 'etherpad_api_host' : 'etherpad_host';
		$checks = [
			new HealthCheckItem(
				'api',
				HealthCheckItem::STATUS_OK,
				$this->l10n->t('Etherpad API reachable'),
				$this->fill(
					$this->l10n->t('{target} — {latency} ms'),
					['target' => $target, 'latency' => (string)$latencyMs],
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
			$this->sessionCookieCheck($settings, $cookieDomain),
		];

		return new HealthCheckResult(
			$settings->etherpadHost,
			$settings->etherpadApiHost,
			$settings->etherpadApiVersion,
			$latencyMs,
			$target,
			$this->bindingService->countPendingDeletes(),
			$this->releasePolicy->knownRelease(),
			$cookieDomain,
			$checks,
		);
	}

	/**
	 * What the Etherpad session cookie will look like, and why — the one
	 * place an admin can see it. When the detection is wrong the symptom is
	 * that no protected pad opens and nothing else says why.
	 *
	 * Reads the answer the open path is using and probes the submitted host
	 * separately: those two disagreeing *is* the lockout.
	 *
	 * Read, never resolved. Resolving would refresh an expired cache, which
	 * probes the stored host and writes — so testing an unsaved form would
	 * teach the open path something, and park real opens in the failure
	 * backoff because somebody pressed a button.
	 *
	 * Everything lands on `etherpad_session_cookie`: the panel keeps the
	 * highest severity per field, so sharing one with the API lines means
	 * losing to them.
	 */
	private function sessionCookieCheck(ValidatedAdminSettings $settings, ?CookieDomainDecision $cookieDomain): HealthCheckItem {
		// check() decides the cookie domain exactly when protected pads are on.
		if ($cookieDomain === null) {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_SKIPPED,
				$this->l10n->t('Session cookie: not checked, protected pads are off'),
			);
		}

		return $this->withCrossSiteNote(
			$this->sessionCookieDiagnosis($settings),
			$this->crossSiteNote($settings, $cookieDomain),
		);
	}

	private function sessionCookieDiagnosis(ValidatedAdminSettings $settings): HealthCheckItem {
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
			// The full timeout, not the open path's three seconds: a slow
			// but healthy pad server is not a misconfiguration.
			$release = $this->etherpadClient->detectReleaseVersion(
				$settings->etherpadApiHost,
				EtherpadClient::REQUEST_TIMEOUT_SECONDS,
			);
			$probeError = null;
		} catch (\Throwable $e) {
			$release = '';
			$probeError = $e;
		}

		$override = $this->releasePolicy->overrideMode();
		if ($override !== EtherpadReleasePolicy::OVERRIDE_AUTO) {
			return $this->overriddenSessionCookieItem($override, $release);
		}

		if ($release === '') {
			// Not a warning: the cookie stays readable, which is what every
			// Etherpad before 3.0 needs anyway. The reason still matters —
			// DNS, TLS, an unrouted /health and proxy auth are different
			// fixes, and this class already tells them apart.
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_SKIPPED,
				$this->l10n->t('Session cookie: readable by scripts'),
				$this->l10n->t('The Etherpad release could not be read from /health, so the cookie is left readable.')
					. ' ' . $this->describeProbeFailure($probeError),
			);
		}

		// The stored host is the one the open path resolves. Comparing a
		// probe of an address being typed against what pads are doing would
		// read every planned migration as a live lockout.
		// Before the first save there is none, and no address is in use: the
		// one tested is not it either.
		$configuredHost = $this->etherpadClient->configuredApiHost();
		if (rtrim($configuredHost, '/') !== rtrim($settings->etherpadApiHost, '/')) {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_OK,
				$this->fill(
					EtherpadReleasePolicy::allowsHttpOnly($release)
						? $this->l10n->t('Session cookie: this address reports Etherpad {release} and would be sent an HttpOnly cookie')
						: $this->l10n->t('Session cookie: this address reports Etherpad {release} and would be sent a script-readable cookie'),
					['release' => DiagnosticText::shorten($release)],
				),
				$this->l10n->t('Not the address currently in use — save the settings to switch pads over to it.'),
			);
		}

		$knownRelease = $this->releasePolicy->knownRelease($configuredHost);
		if ($knownRelease === '') {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_OK,
				$this->fill(
					$this->l10n->t('Session cookie: Etherpad {release}, checked on the first protected pad open'),
					['release' => DiagnosticText::shorten($release)],
				),
			);
		}

		$sending = EtherpadReleasePolicy::allowsHttpOnly($knownRelease);
		if ($sending !== EtherpadReleasePolicy::allowsHttpOnly($release)) {
			return $this->sessionCookieItem(
				HealthCheckItem::STATUS_WARNING,
				$this->l10n->t('Etherpad session cookie'),
				$this->fill(
					$sending
						? $this->l10n->t('Pads are being sent an HttpOnly cookie, from Etherpad {known} seen earlier, but this server reports {release}, which reads the cookie in the browser. Protected pads will not open until that is checked again.')
						: $this->l10n->t('This server reports Etherpad {release}, which reads the session server-side, but pads are still being sent a script-readable cookie from Etherpad {known} seen earlier.'),
					['release' => DiagnosticText::shorten($release), 'known' => DiagnosticText::shorten($knownRelease)],
				),
			);
		}

		return $this->sessionCookieItem(
			HealthCheckItem::STATUS_OK,
			$this->fill(
				$sending
					? $this->l10n->t('Session cookie kept from scripts (Etherpad {release})')
					: $this->l10n->t('Session cookie readable by scripts (Etherpad {release})'),
				['release' => DiagnosticText::shorten($knownRelease)],
			),
		);
	}

	/**
	 * Adds to whatever the line says what crossSiteNote() has to say about
	 * how far the cookie travels.
	 *
	 * A cross-site embed needs `SameSite=None` on the Etherpad cookie, set by
	 * hand, and Nextcloud authenticating without its session cookie. Whether
	 * Nextcloud authenticates that way, this app cannot tell. The SameSite
	 * attribute of Nextcloud's own session cookie, read at runtime, can say
	 * why that cookie does not reach such a frame; it does not confirm that
	 * a frame is logged in.
	 */
	private function withCrossSiteNote(HealthCheckItem $item, string $note): HealthCheckItem {
		if ($note === '') {
			return $item;
		}

		// One slot, two things worth saying. Folded in rather than returned
		// instead: the line underneath can be the one announcing that no
		// protected pad opens at all, and a note about how far the cookie
		// travels must not push that off the panel.
		//
		// The label moves into the detail when there was none. A passing
		// line keeps its text there — the release, the cookie verdict — and
		// the panel only renders a label while the status is ok.
		return $this->sessionCookieItem(
			HealthCheckItem::STATUS_WARNING,
			$item->label,
			trim(($item->detail !== '' ? $item->detail : $item->label) . ' ' . $note),
		);
	}

	/** What is worth saying about how far the cookie travels, or ''. */
	private function crossSiteNote(ValidatedAdminSettings $settings, CookieDomainDecision $cookieDomain): string {
		$unrecognised = $this->padSessionService->unrecognisedSameSite();
		if ($unrecognised !== '') {
			return $this->fill(
				$this->l10n->t('"{value}" is not one of lax or none, so the session cookie stays same-site. Set one of those two, or remove the setting.'),
				['value' => $unrecognised],
			);
		}

		if ($this->padSessionService->sameSiteMode() !== PadSessionService::SAME_SITE_NONE) {
			return $this->embedOriginNote($settings, $cookieDomain);
		}

		$note = $this->l10n->t('The session cookie is also sent to other sites (SameSite=None), set by hand so a foreign site can frame the embed routes. It only works where Nextcloud authenticates without a cookie — proxy REMOTE_USER, Kerberos, SAML in environment mode.');
		$nextcloudSameSite = $this->nextcloudSessionSameSite();
		if (in_array($nextcloudSameSite, ['lax', 'strict'], true)) {
			$note .= ' ' . $this->fill(
				$this->l10n->t('Nextcloud sends its own session cookie as {samesite}, so a cross-site frame is not logged in through it.'),
				['samesite' => ucfirst($nextcloudSameSite)],
			);
		}

		return $note;
	}

	/**
	 * An embed origin outside the cookie's domain while the cookie stays
	 * same-site. Such an origin may still be on the same site; if it is
	 * not, the embedded pad gets no session and nothing else says why.
	 *
	 * The origins and the domain are the form's, the domain from the same
	 * decision as the protected-pads line. Where that decision is not ok,
	 * the protected-pads line already warns about the cookie domain, and
	 * SameSite=None would not mend it, so nothing is added.
	 *
	 * CookieDomainPolicy::isCoveredBy() says why 'outside' only means
	 * 'worth checking'. Over-warning is acceptable: this asks an admin to
	 * check, where deciding the cookie from the same comparison would need
	 * a public suffix list.
	 */
	private function embedOriginNote(ValidatedAdminSettings $settings, CookieDomainDecision $cookieDomain): string {
		$origins = $this->trustedEmbedOriginsNormalizer->parse($settings->trustedEmbedOrigins);
		if ($origins === [] || !$cookieDomain->isOk()) {
			return '';
		}

		$domain = $cookieDomain->effectiveDomain;
		$outside = array_filter(
			$origins,
			fn (string $origin): bool => !$this->cookieDomainPolicy->isCoveredBy($origin, $domain),
		);
		if ($outside === []) {
			return '';
		}

		return $this->fill(
			$this->l10n->t('{origins} may frame the embed routes but is not covered by the session cookie domain {domain}. If it is on another site, the embedded pad gets no Etherpad session unless etherpad_session_cookie_samesite is set to none.'),
			['origins' => DiagnosticText::shorten(implode(', ', $outside)), 'domain' => $domain !== '' ? $domain : '(host-only)'],
		);
	}

	/** Seam: there is no session in a unit test, so this is overridable. */
	protected function nextcloudSessionSameSite(): string {
		return strtolower(trim(session_get_cookie_params()['samesite'] ?? ''));
	}

	/** The same vocabulary the rest of this class uses for a failed call. */
	private function describeProbeFailure(?\Throwable $error): string {
		if ($error === null) {
			return '';
		}

		$detail = $error->getMessage();
		$previous = $error->getPrevious();
		if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
			$detail .= ' (' . $previous->getMessage() . ')';
		}
		$hint = $this->hintForReason($this->classifyFailure($detail));

		return DiagnosticText::shorten($detail) . ($hint !== '' ? ' ' . $hint : '');
	}

	/**
	 * A hand-set flag, reported by what it costs. The two values are not
	 * symmetric: `yes` below 3.0 stops every protected pad and has to
	 * shout, `no` only gives up a hardening — warning on that teaches an
	 * admin to ignore the line that matters. Both name the release, so
	 * somebody who reached for the escape hatch can see if they may stop.
	 */
	private function overriddenSessionCookieItem(string $override, string $release): HealthCheckItem {
		$forcedOn = $override === EtherpadReleasePolicy::OVERRIDE_YES;
		$serverReads = $release === ''
			? $this->l10n->t('The pad server did not report its release.')
			: $this->fill(
				EtherpadReleasePolicy::allowsHttpOnly($release)
					? $this->l10n->t('This server reports Etherpad {release}, which reads the session server-side — automatic detection would set the same thing.')
					: $this->l10n->t('This server reports Etherpad {release}, which reads the session in the browser.'),
				['release' => DiagnosticText::shorten($release)],
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
	 * Matching is on substrings rather than exception subtypes because
	 * EtherpadClient puts many failure shapes into one message, Etherpad's own
	 * wording among them. If that wording changes the classification just
	 * falls through to REASON_UNKNOWN; the error itself still surfaces.
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
		$apiField = ValidatedAdminSettings::isOwnApiHost($settings->etherpadApiHost, $settings->etherpadHost) ? 'etherpad_api_host' : 'etherpad_host';

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

	private function nowSeconds(): float {
		return (float)$this->timeFactory->now()->format('U.u');
	}
}
