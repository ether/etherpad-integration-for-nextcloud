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

	/**
	 * Which type a pad falls back to, most preferred first.
	 *
	 * With two modes the loop below can never show this: whichever was asked
	 * for is the disabled one, so a single candidate is left. It is a
	 * constant so that the preference can be asserted directly - protected
	 * first, because the other direction hands out a pad anyone holding its
	 * id can read - rather than left to the order someone happened to write.
	 */
	public const FALLBACK_ORDER = [PadAccessMode::Protected, PadAccessMode::Public];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Whether pads of this type may be created here.
	 *
	 * A mode nobody knows answers false, the same as one switched off. The
	 * two are told apart by the callers that can act on the difference:
	 * requireEnabled() and resolveCreatableMode() refuse an unknown value
	 * outright rather than reporting a disabled pad type for something that
	 * was never one.
	 */
	public function isEnabled(string $accessMode): bool {
		$mode = PadAccessMode::tryFrom($accessMode);
		return $mode !== null && $this->isModeEnabled($mode);
	}

	private function isModeEnabled(PadAccessMode $mode): bool {
		return match ($mode) {
			PadAccessMode::Protected => $this->flag(self::SETTING_PROTECTED),
			PadAccessMode::Public => $this->flag(self::SETTING_PUBLIC),
		};
	}

	/**
	 * Whether a pad can be provisioned locally at all. External pads are not a
	 * pad type and are not covered — they follow `allow_external_pads`.
	 *
	 * Short-circuits, so a case with no arm in isModeEnabled() only reaches
	 * it once every case before it is switched off. Psalm is what actually
	 * holds that, not this loop.
	 */
	public function hasAnyEnabledType(): bool {
		foreach (PadAccessMode::cases() as $mode) {
			if ($this->isModeEnabled($mode)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @throws PadTypeDisabledException when pads of this type are switched off
	 * @throws \InvalidArgumentException when $accessMode is not a known mode
	 */
	public function requireEnabled(string $accessMode): void {
		$mode = PadAccessMode::tryFrom($accessMode);
		if ($mode === null) {
			throw new \InvalidArgumentException('Unsupported access mode: ' . $accessMode);
		}
		if ($this->isModeEnabled($mode)) {
			return;
		}
		throw new PadTypeDisabledException($accessMode);
	}

	/**
	 * Pick a mode that may actually be created, preferring the requested one.
	 *
	 * A disabled mode falls back rather than refusing, because refusing there
	 * would strand the user without protecting anything: a template carries
	 * the mode of the pad it was made from, and a `.pad` file that arrived
	 * outside the UI (WebDAV, another integration) has to become *some* pad
	 * on first open. A mode that is not one at all is a different matter, and
	 * is refused: no caller can produce it, and substituting for it would
	 * hand back a pad of a type nobody asked for.
	 *
	 * Note this can widen access — a protected template becomes a public pad
	 * when protected pads are off. That is the instance's only option at that
	 * point, but it is a downgrade in the security-relevant direction.
	 *
	 * @throws PadTypeDisabledException when no pad type is enabled at all
	 * @throws \InvalidArgumentException when $requested is not a known mode
	 */
	public function resolveCreatableMode(string $requested): string {
		$mode = PadAccessMode::tryFrom($requested);
		if ($mode === null) {
			throw new \InvalidArgumentException('Unsupported access mode: ' . $requested);
		}
		if ($this->isModeEnabled($mode)) {
			return $requested;
		}
		// In FALLBACK_ORDER's order, which is the preference and not an
		// accident of iteration. No test can see that today: the requested
		// mode is one of the two and it is disabled, so a single candidate
		// is ever left. Reversing this line is invisible until a third mode
		// exists, which is when it starts deciding something.
		foreach (self::FALLBACK_ORDER as $fallback) {
			if ($this->isModeEnabled($fallback)) {
				return $fallback->value;
			}
		}
		throw new PadTypeDisabledException();
	}

	private function flag(string $key): bool {
		return (string)$this->config->getAppValue(Application::APP_ID, $key, 'yes') === 'yes';
	}
}
