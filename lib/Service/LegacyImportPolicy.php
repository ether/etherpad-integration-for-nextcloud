<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\IConfig;

/**
 * Whether a legacy Ownpad `.pad` may bring a group pad in with it. Its
 * pad-id is user input and names the Etherpad group a session is minted
 * for - see docs/legacy-ownpad-migration.md.
 */
class LegacyImportPolicy {
	public const SETTING_PROTECTED_IMPORT = 'allow_legacy_protected_import';

	/** Opt-in, like allow_external_pads. The settings page reads it too. */
	public const DEFAULT_PROTECTED_IMPORT = 'no';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function allowsProtectedImport(): bool {
		return (string)$this->config->getAppValue(
			Application::APP_ID,
			self::SETTING_PROTECTED_IMPORT,
			self::DEFAULT_PROTECTED_IMPORT,
		) === 'yes';
	}
}
