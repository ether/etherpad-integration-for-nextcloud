<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * The legacy `.pad` names a group pad and this instance does not import
 * those. Surfaces as 403 with code `legacy_protected_import_disabled`.
 */
final class LegacyProtectedImportDisabledException extends \RuntimeException {
}
