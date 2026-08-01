<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Decides which `Domain=` the Etherpad session cookie gets, and whether
 * protected pads can work with it at all.
 *
 * A browser only accepts a `Set-Cookie` whose `Domain=` covers the host that
 * sent it, so a response from Nextcloud can never set a cookie Etherpad would
 * receive unless the two share a parent domain. `SameSite=None` does not help
 * with that: it governs whether an accepted cookie is sent cross-site.
 *
 * Pure string handling, no I/O — callers pass the hosts in.
 *
 * @psalm-api
 */
class CookieDomainPolicy {
	/**
	 * @param string|null $configured The stored setting: null when the admin
	 *   has never saved one (derive), '' when a host-only cookie was chosen
	 *   deliberately, otherwise the explicit domain.
	 */
	public function decide(string $nextcloudUrl, string $etherpadUrl, ?string $configured): CookieDomainDecision {
		$nextcloudHost = $this->extractHost($nextcloudUrl);
		$etherpadHost = $this->extractHost($etherpadUrl);

		if ($nextcloudHost === '' || $etherpadHost === '') {
			// A value that is present but unparseable is a different problem
			// from one that was never set, and deserves a different message.
			$reason = (trim($nextcloudUrl) !== '' && trim($etherpadUrl) !== '')
				? CookieDomainDecision::REASON_INVALID_HOST
				: CookieDomainDecision::REASON_NOT_CONFIGURED;
			return $this->decision('', CookieDomainDecision::STATUS_UNKNOWN, $reason, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_HOST_ONLY);
		}

		// Before any cookie-capability test: identical hosts need no `Domain=`
		// at all, which is exactly what makes a plain `localhost` or single-IP
		// setup work. Warning about those would be wrong.
		if ($nextcloudHost === $etherpadHost) {
			return $this->decision('', CookieDomainDecision::STATUS_OK, CookieDomainDecision::REASON_SAME_HOST, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_HOST_ONLY);
		}

		if ($configured !== null) {
			return $this->decideConfigured(trim($configured), $nextcloudHost, $etherpadHost);
		}

		return $this->decideDerived($nextcloudHost, $etherpadHost);
	}

	/**
	 * Normalises the stored setting into what decide() expects.
	 *
	 * Installations from before the "was configured" flag existed carry only
	 * the value, so a non-empty one counts as chosen. Treating it as unset
	 * would silently re-derive over a domain an admin had picked. Every caller
	 * goes through here so they cannot feed the policy different inputs.
	 */
	public function storedValue(string $stored, bool $configuredFlag): ?string {
		$trimmed = trim($stored);
		if (!$configuredFlag && $trimmed === '') {
			return null;
		}
		return $trimmed;
	}

	/** The `Domain=` value alone, for callers that only set the cookie. */
	public function resolve(string $nextcloudUrl, string $etherpadUrl, ?string $configured): string {
		return $this->decide($nextcloudUrl, $etherpadUrl, $configured)->effectiveDomain;
	}

	private function decideConfigured(string $configured, string $nextcloudHost, string $etherpadHost): CookieDomainDecision {
		if ($configured === '') {
			// Deliberate host-only cookie, but the hosts differ, so it never
			// reaches Etherpad.
			return $this->decision('', CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_HOST_ONLY_ACROSS_HOSTS, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_HOST_ONLY, $this->workableDomain($nextcloudHost, $etherpadHost));
		}

		$bare = ltrim(strtolower($configured), '.');
		$domain = '.' . $bare;

		if (!$this->domainCovers($bare, $nextcloudHost) || !$this->domainCovers($bare, $etherpadHost)) {
			// Naming the domain that would work turns "this is wrong" into
			// something the admin can act on without working it out by hand.
			return $this->decision($domain, CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_CONFIGURED_DOMAIN_MISMATCH, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_CONFIGURED, $this->workableDomain($nextcloudHost, $etherpadHost));
		}

		// Covering both hosts is not enough: a browser rejects a public suffix
		// no matter who asked for it, so the same check applies here.
		if ($this->mayBePublicSuffix(explode('.', $bare))) {
			return $this->decision($domain, CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_MAY_BE_PUBLIC_SUFFIX, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_CONFIGURED);
		}

		return $this->decision($domain, CookieDomainDecision::STATUS_OK, CookieDomainDecision::REASON_CONFIGURED, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_CONFIGURED);
	}

	private function decideDerived(string $nextcloudHost, string $etherpadHost): CookieDomainDecision {
		if ($this->isUnsuitableForDomainCookie($nextcloudHost) || $this->isUnsuitableForDomainCookie($etherpadHost)) {
			return $this->decision('', CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_HOST_NOT_COOKIE_CAPABLE, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_HOST_ONLY);
		}

		$common = $this->commonParentLabels($nextcloudHost, $etherpadHost);
		if (count($common) < 2) {
			return $this->decision('', CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_NO_COMMON_PARENT, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_HOST_ONLY);
		}

		$domain = '.' . implode('.', $common);
		if ($this->mayBePublicSuffix($common)) {
			return $this->decision($domain, CookieDomainDecision::STATUS_WARNING, CookieDomainDecision::REASON_MAY_BE_PUBLIC_SUFFIX, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_DERIVED);
		}

		return $this->decision($domain, CookieDomainDecision::STATUS_OK, CookieDomainDecision::REASON_COMMON_PARENT, $nextcloudHost, $etherpadHost, CookieDomainDecision::SOURCE_DERIVED);
	}

	/**
	 * The domain these two hosts could actually share, or empty when there is
	 * none. Derived exactly as an unconfigured instance would, so the
	 * suggestion is the value the field would hold on its own.
	 */
	private function workableDomain(string $nextcloudHost, string $etherpadHost): string {
		$derived = $this->decideDerived($nextcloudHost, $etherpadHost);
		return $derived->isOk() ? $derived->effectiveDomain : '';
	}

	/**
	 * Suffix test on label boundaries, so `example.org` does not cover
	 * `evil-example.org`.
	 */
	private function domainCovers(string $domain, string $host): bool {
		return $host === $domain || str_ends_with($host, '.' . $domain);
	}

	/** @return list<string> The shared trailing labels, in host order. */
	private function commonParentLabels(string $nextcloudHost, string $etherpadHost): array {
		$left = array_reverse(explode('.', $nextcloudHost));
		$right = array_reverse(explode('.', $etherpadHost));
		$common = [];
		$limit = min(count($left), count($right));
		for ($i = 0; $i < $limit; $i++) {
			if ($left[$i] !== $right[$i]) {
				break;
			}
			$common[] = $left[$i];
		}
		return array_reverse($common);
	}

	/**
	 * Conservative check for a shared parent that is really a public suffix
	 * (`co.uk`, `github.io`), where browsers reject the cookie outright.
	 *
	 * This is a heuristic, not a Public Suffix List lookup: the list cannot be
	 * derived algorithmically and the app ships no runtime dependencies to
	 * carry one. It therefore under-reports, which is why the matching status
	 * is "may be" rather than a verdict — the browser enforces the real rule
	 * either way.
	 *
	 * @param list<string> $labels
	 */
	private function mayBePublicSuffix(array $labels): bool {
		if (count($labels) !== 2) {
			return false;
		}
		[$second, $top] = $labels;

		// `something.co.uk`-style: a two-letter country code behind one of the
		// generic second-level labels registrars use under it.
		if (strlen($top) === 2 && in_array($second, ['ac', 'co', 'com', 'edu', 'gov', 'net', 'org', 'or', 'ne', 'in'], true)) {
			return true;
		}

		// Hosting domains where every customer gets a subdomain, so a shared
		// parent means unrelated sites rather than one deployment.
		return in_array(implode('.', $labels), [
			'github.io', 'gitlab.io', 'netlify.app', 'vercel.app', 'pages.dev',
			'herokuapp.com', 'azurewebsites.net', 'cloudfront.net', 'appspot.com',
		], true);
	}

	private function isUnsuitableForDomainCookie(string $host): bool {
		return filter_var($host, FILTER_VALIDATE_IP) !== false
			|| $host === 'localhost'
			|| str_ends_with($host, '.localhost')
			|| !str_contains($host, '.');
	}

	private function extractHost(string $urlOrHost): string {
		$value = strtolower(trim($urlOrHost));
		if ($value === '') {
			return '';
		}
		$host = parse_url($value, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			$host = preg_replace('/:\d+$/', '', $value) ?? '';
		}
		$host = trim(strtolower($host), "[] \t\n\r\0\x0B.");
		return $this->isValidHost($host) ? $host : '';
	}

	private function isValidHost(string $host): bool {
		if ($host === '' || strlen($host) > 253) {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return true;
		}
		foreach (explode('.', $host) as $label) {
			if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
				return false;
			}
		}
		return true;
	}

	private function decision(string $domain, string $status, string $reason, string $nextcloudHost, string $etherpadHost, string $source, string $suggestedDomain = ''): CookieDomainDecision {
		return new CookieDomainDecision($domain, $status, $reason, $nextcloudHost, $etherpadHost, $source, $suggestedDomain);
	}
}
