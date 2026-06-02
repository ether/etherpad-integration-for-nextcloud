<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class CSPListener implements IEventListener {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$domains = $this->getAllowedFrameDomains();
		if ($domains === []) {
			return;
		}

		$policy = new ContentSecurityPolicy();
		foreach ($domains as $domain) {
			$policy->addAllowedFrameDomain($domain);
			$policy->addAllowedChildSrcDomain($domain);
		}
		$event->addPolicy($policy);
	}

	/** @return list<string> */
	private function getAllowedFrameDomains(): array {
		$domains = [];

		$localHost = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''), '/');
		if ($localHost !== '') {
			$domains[$localHost] = true;
		}

		if ((string)$this->config->getAppValue('etherpad_nextcloud', 'allow_external_pads', 'no') !== 'yes') {
			return array_keys($domains);
		}

		$rawAllowlist = trim((string)$this->config->getAppValue('etherpad_nextcloud', 'external_pad_allowlist', ''));
		if ($rawAllowlist === '') {
			// An empty allowlist means "allow all public HTTPS hosts" for the
			// server-side snapshot fetch (see ExternalPadExportFetcher), but we
			// must NOT mirror that into CSP: this listener handles *every*
			// AddContentSecurityPolicyEvent, so emitting `https:` here would
			// relax frame-src/child-src on all of Nextcloud, not just pad pages.
			// External pads are shown as locally rendered snapshots (no live
			// iframe to the external host), so framing them is unnecessary;
			// only concrete allowlisted hosts ever get a frame relaxation.
			return array_keys($domains);
		}

		$entries = preg_split('/[\s,;]+/', $rawAllowlist) ?: [];
		foreach ($entries as $entry) {
			$normalized = $this->normalizeExternalAllowlistEntry((string)$entry);
			if ($normalized !== null) {
				$domains[$normalized] = true;
			}
		}

		return array_keys($domains);
	}

	private function normalizeExternalAllowlistEntry(string $entry): ?string {
		$trimmed = trim($entry);
		if ($trimmed === '') {
			return null;
		}

		if (preg_match('#^https?://#i', $trimmed) === 1) {
			$parts = parse_url($trimmed);
			if (!is_array($parts)) {
				return null;
			}
			$scheme = strtolower((string)($parts['scheme'] ?? ''));
			$host = strtolower((string)($parts['host'] ?? ''));
			$port = isset($parts['port']) ? (int)$parts['port'] : 443;
			if ($scheme !== 'https' || $host === '' || $port <= 0 || $port > 65535) {
				return null;
			}
			return $port === 443 ? 'https://' . $host : 'https://' . $host . ':' . $port;
		}

		$host = strtolower(trim($trimmed, ". \t\n\r\0\x0B"));
		if ($host === '') {
			return null;
		}

		return 'https://' . $host;
	}
}
