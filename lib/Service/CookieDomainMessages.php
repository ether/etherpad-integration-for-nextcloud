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
		$hosts = $this->fill(
			$this->l10n->t('Nextcloud runs on {nextcloud_host}, Etherpad on {etherpad_host}.'),
			['nextcloud_host' => $decision->nextcloudHost, 'etherpad_host' => $decision->etherpadHost],
		);

		return match ($decision->reason) {
			CookieDomainDecision::REASON_NO_COMMON_PARENT => $hosts . ' ' . $this->l10n->t(
				'They share no parent domain, so Nextcloud cannot set a session cookie that reaches Etherpad, and protected pads will not open. Move one of them to a shared domain, or switch protected pads off.'
			),
			CookieDomainDecision::REASON_HOST_NOT_COOKIE_CAPABLE => $hosts . ' ' . $this->l10n->t(
				'IP addresses and single-label hosts cannot share a cookie domain. Use hostnames under a common domain, or serve both from the same host.'
			),
			CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH => $hosts . ' ' . $this->fill(
				$this->l10n->t('The configured cookie domain {domain} does not cover both of them, so the browser will reject the session cookie.'),
				['domain' => $decision->effectiveDomain],
			),
			CookieDomainDecision::REASON_HOST_ONLY_ACROSS_HOSTS => $hosts . ' ' . $this->l10n->t(
				'The cookie domain is empty, so the session cookie stays on the Nextcloud host and never reaches Etherpad.'
			),
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

	/** @param array<string,string> $parameters */
	private function fill(string $text, array $parameters): string {
		foreach ($parameters as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}
}
