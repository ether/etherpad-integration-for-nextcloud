<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * Thrown by the legacy Ownpad migration path when the `.pad` file names a
 * group pad and this instance does not allow importing those.
 *
 * The pad-id in a legacy `.pad` is written by whoever wrote the file, and
 * for a group pad it decides which Etherpad group a session is minted for.
 * A genuine Ownpad pad and a group pad belonging to someone else on a
 * shared Etherpad server look the same from here, so an instance sharing
 * its Etherpad with anything outside this Nextcloud can switch the import
 * off. Surfaces as HTTP 403 with code `legacy_protected_import_disabled`.
 */
final class LegacyProtectedImportDisabledException extends \RuntimeException {
}
