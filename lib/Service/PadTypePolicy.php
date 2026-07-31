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

	public function isEnabled(string $accessMode): bool {
		return match ($accessMode) {
			BindingService::ACCESS_PROTECTED => $this->flag(self::SETTING_PROTECTED),
			BindingService::ACCESS_PUBLIC => $this->flag(self::SETTING_PUBLIC),
			default => false,
		};
	}

	/** @throws PadTypeDisabledException */
	public function requireEnabled(string $accessMode): void {
		if ($this->isEnabled($accessMode)) {
			return;
		}
		throw new PadTypeDisabledException($accessMode === BindingService::ACCESS_PUBLIC
			? 'Public pads are disabled on this instance.'
			: 'Protected pads are disabled on this instance.');
	}

	/**
	 * A template carries the access mode of the pad it was made from. When
	 * that mode is switched off, create the pad in the other enabled mode
	 * rather than refusing: the template's content is what the user is after,
	 * and the policy still holds for the resulting pad.
	 *
	 * @throws PadTypeDisabledException when no pad type is enabled at all
	 */
	public function resolveForTemplate(string $requested): string {
		if ($this->isEnabled($requested)) {
			return $requested;
		}
		foreach ([BindingService::ACCESS_PROTECTED, BindingService::ACCESS_PUBLIC] as $fallback) {
			if ($this->isEnabled($fallback)) {
				return $fallback;
			}
		}
		throw new PadTypeDisabledException('Creating pads is disabled on this instance.');
	}

	private function flag(string $key): bool {
		return (string)$this->config->getAppValue(Application::APP_ID, $key, 'yes') === 'yes';
	}
}
