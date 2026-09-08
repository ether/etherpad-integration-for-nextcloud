<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCP\IConfig;

/**
 * Which pad types an instance offers.
 *
 * Both types default to enabled, so an installation that never touches the
 * settings behaves exactly as before. The policy governs *creation* only:
 * pads that already exist keep opening whatever the settings say, which is
 * why the checks live in the create paths and not in
 * `PadBootstrapService::provisionPadId()` — that one also serves
 * `initializeMissingFrontmatter` for existing files.
 *
 * External pads are deliberately not covered here. They are always public in
 * Etherpad terms but are governed solely by `allow_external_pads`, enforced
 * in ExternalPadExportFetcher and CSPListener.
 */
class PadTypePolicy {
	public const SETTING_PROTECTED = 'enable_protected_pads';
	public const SETTING_PUBLIC = 'enable_public_pads';

	public function __construct(
		private IConfig $config,
	) {
	}

	/** A mode nobody knows counts as unavailable, like one switched off. */
	public function isEnabled(string $accessMode): bool {
		return match (PadAccessMode::tryFrom($accessMode)) {
			PadAccessMode::Protected => $this->flag(self::SETTING_PROTECTED),
			PadAccessMode::Public => $this->flag(self::SETTING_PUBLIC),
			null => false,
		};
	}

	/**
	 * Whether a pad can be provisioned locally at all. External pads are not a
	 * pad type and are not covered — they follow `allow_external_pads`.
	 */
	public function hasAnyEnabledType(): bool {
		foreach (PadAccessMode::cases() as $mode) {
			if ($this->isEnabled($mode->value)) {
				return true;
			}
		}
		return false;
	}

	/** @throws PadTypeDisabledException */
	public function requireEnabled(string $accessMode): void {
		if ($this->isEnabled($accessMode)) {
			return;
		}
		throw new PadTypeDisabledException($accessMode);
	}

	/**
	 * Pick a mode that may actually be created, preferring the requested one.
	 *
	 * Used where refusing would strand the user rather than protect anything:
	 * a template carries the mode of the pad it was made from, and a `.pad`
	 * file that arrived outside the UI (WebDAV, another integration) has to
	 * become *some* pad on first open. Falling back keeps the content
	 * reachable while the policy still holds for the resulting pad.
	 *
	 * Note this can widen access — a protected template becomes a public pad
	 * when protected pads are off. That is the instance's only option at that
	 * point, but it is a downgrade in the security-relevant direction.
	 *
	 * @throws PadTypeDisabledException when no pad type is enabled at all
	 */
	public function resolveCreatableMode(string $requested): string {
		if (PadAccessMode::tryFrom($requested) === null) {
			throw new \InvalidArgumentException('Unsupported access mode: ' . $requested);
		}
		if ($this->isEnabled($requested)) {
			return $requested;
		}
		// Whichever mode was asked for is disabled, so with two of them the
		// order settles nothing today. It is written out rather than taken
		// from PadAccessMode::cases() for the day there is a third: which
		// downgrade is acceptable is a decision, not a declaration order.
		foreach ([PadAccessMode::Protected, PadAccessMode::Public] as $fallback) {
			if ($this->isEnabled($fallback->value)) {
				return $fallback->value;
			}
		}
		throw new PadTypeDisabledException();
	}

	private function flag(string $key): bool {
		return (string)$this->config->getAppValue(Application::APP_ID, $key, 'yes') === 'yes';
	}
}
