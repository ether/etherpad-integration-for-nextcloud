<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\IL10N;

/**
 * Turns a CookieDomainDecision into admin-facing text. Kept apart from the
 * policy so the decision stays free of translations, and shared between the
 * settings page and the health check so both word it the same way.
 *
 * @psalm-api
 */
class CookieDomainMessages {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Empty when there is nothing worth telling the admin.
	 *
	 * Placeholders are substituted here rather than handed to IL10N, matching
	 * what EtherpadHealthCheckService does: Nextcloud's L10NString has been
	 * seen leaking the literal placeholder through in some catalog setups.
	 */
	public function describe(CookieDomainDecision $decision): string {
		$hosts = $this->hostSentence($decision);

		$suggestion = $decision->suggestedDomain !== ''
			? ' ' . $this->fill($this->l10n->t('{domain} would cover both.'), ['domain' => $decision->suggestedDomain])
			: '';

		return match ($decision->reason) {
			CookieDomainDecision::REASON_NO_COMMON_PARENT => $hosts . ' ' . $this->l10n->t(
				'They share no parent domain, so Nextcloud cannot set a session cookie that reaches Etherpad, and protected pads will not open. If a proxy already serves Etherpad under the Nextcloud domain, enter that address as the base URL — pad links use it, so the cookie follows. Otherwise move one of the hosts, or switch protected pads off.'
			),
			CookieDomainDecision::REASON_HOST_NOT_COOKIE_CAPABLE => $hosts . ' ' . $this->l10n->t(
				'IP addresses and single-label hosts cannot share a cookie domain. Use hostnames under a common domain, or serve both from the same host.'
			),
			CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH => $hosts . ' ' . $this->fill(
				$this->l10n->t('The configured cookie domain {domain} does not cover both of them, so the browser will reject the session cookie.'),
				['domain' => $decision->effectiveDomain],
			) . $suggestion,
			CookieDomainDecision::REASON_HOST_ONLY_ACROSS_HOSTS => $hosts . ' ' . $this->l10n->t(
				'The cookie domain is empty, so the session cookie stays on the Nextcloud host and never reaches Etherpad.'
			) . $suggestion,
			CookieDomainDecision::REASON_MAY_BE_PUBLIC_SUFFIX => $hosts . ' ' . $this->fill(
				$this->l10n->t('{domain} looks like a public suffix such as co.uk, which browsers refuse as a cookie domain. If it is one, protected pads will not open.'),
				['domain' => $decision->effectiveDomain],
			),
			CookieDomainDecision::REASON_INVALID_HOST => $this->l10n->t(
				'The Etherpad base URL could not be read as a hostname, so the session cookie domain cannot be checked.'
			),
			default => '',
		};
	}

	/** The same verdict as one line of the connection test. */
	public function asCheckItem(?CookieDomainDecision $decision): HealthCheckItem {
		$label = $this->l10n->t('Protected pads: session cookie');
		$field = 'etherpad_cookie_domain';
		if ($decision === null) {
			return new HealthCheckItem('protected_pads', HealthCheckItem::STATUS_SKIPPED, $label, $this->l10n->t('Protected pads are switched off.'), $field);
		}
		if ($decision->isOk()) {
			$detail = $decision->effectiveDomain !== ''
				? $decision->effectiveDomain
				: $this->l10n->t('Host-only cookie, Nextcloud and Etherpad share a host.');
			return new HealthCheckItem('protected_pads', HealthCheckItem::STATUS_OK, $label, $detail, $field);
		}
		// A reason without its own wording would otherwise render as a blank
		// warning. Say plainly that the check came up short, so a forgotten
		// mapping reads as one instead of hiding behind the hosts alone.
		$detail = $this->describe($decision);
		if (trim($detail) === '') {
			$detail = $this->hostSentence($decision) . ' ' . $this->l10n->t('The session cookie domain could not be verified.');
		}
		return new HealthCheckItem('protected_pads', HealthCheckItem::STATUS_WARNING, $label, $detail, $field);
	}

	private function hostSentence(CookieDomainDecision $decision): string {
		return $this->fill(
			$this->l10n->t('Nextcloud runs on {nextcloud_host}, Etherpad on {etherpad_host}.'),
			['nextcloud_host' => $decision->nextcloudHost, 'etherpad_host' => $decision->etherpadHost],
		);
	}

	/** @param array<string,string> $parameters */
	private function fill(string $text, array $parameters): string {
		foreach ($parameters as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}
}
