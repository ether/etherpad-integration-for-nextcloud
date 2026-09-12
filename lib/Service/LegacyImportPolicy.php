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
 * Whether legacy Ownpad `.pad` files may still bring a pad in with them.
 *
 * Every other way a binding is made takes a pad-id this app generated. The
 * legacy migration is the one that takes it from the file, which is user
 * input: for a group pad that id names the Etherpad group a later session
 * is minted for, and the session grants what the group holds, not just the
 * pad the file named.
 *
 * Whether that matters depends on the Etherpad server. If nothing but this
 * Nextcloud creates group pads on it, every one of them already has a
 * binding and naming it collides. If anything else does - another Ownpad
 * install, a second Nextcloud, direct use - those pads have no binding
 * here, and nothing distinguishes them from the ones the migration exists
 * for. Hence a switch rather than a check.
 *
 * Public pads are not covered: a pad anyone holding its id can read is not
 * made more reachable by binding a file to it.
 */
class LegacyImportPolicy {
	public const SETTING_PROTECTED_IMPORT = 'allow_legacy_protected_import';

	/**
	 * Off until an admin says otherwise, like allow_external_pads: the risk
	 * needs a shared Etherpad server, and an instance that has one cannot be
	 * asked to notice a setting first. The settings page and its repository
	 * read the key too, so it is stated once.
	 */
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
