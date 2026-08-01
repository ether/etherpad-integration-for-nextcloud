<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * One evaluation of the Etherpad session cookie domain, used by the runtime,
 * the admin page and the health check alike so the value that is set and the
 * value that is reported on cannot drift apart.
 */
class CookieDomainDecision {
	/** Protected pads can work with this domain. */
	public const STATUS_OK = 'ok';
	/** They cannot, or not verifiably — surfaced as a warning, never a blocker. */
	public const STATUS_WARNING = 'warning';
	/** Not enough configuration yet to say anything. */
	public const STATUS_UNKNOWN = 'unknown';

	public const SOURCE_CONFIGURED = 'configured';
	public const SOURCE_DERIVED = 'derived';
	public const SOURCE_HOST_ONLY = 'host_only';

	public const REASON_NOT_CONFIGURED = 'not_configured';
	public const REASON_INVALID_HOST = 'invalid_host';
	public const REASON_SAME_HOST = 'same_host';
	public const REASON_COMMON_PARENT = 'common_parent';
	public const REASON_CONFIGURED = 'configured';
	public const REASON_NO_COMMON_PARENT = 'no_common_parent';
	public const REASON_HOST_NOT_COOKIE_CAPABLE = 'host_not_cookie_capable';
	public const REASON_CONFIGURED_DOMAIN_MISMATCH = 'configured_domain_mismatch';
	public const REASON_HOST_ONLY_ACROSS_HOSTS = 'host_only_across_hosts';
	public const REASON_MAY_BE_PUBLIC_SUFFIX = 'common_parent_may_be_public_suffix';

	public function __construct(
		/** The `Domain=` attribute to send; empty means a host-only cookie. */
		public readonly string $effectiveDomain,
		public readonly string $status,
		public readonly string $reason,
		public readonly string $nextcloudHost,
		public readonly string $etherpadHost,
		public readonly string $source,
	) {
	}

	public function isOk(): bool {
		return $this->status === self::STATUS_OK;
	}
}
