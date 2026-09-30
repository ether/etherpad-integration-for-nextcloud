<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/** The other server has no such pad, or none it lets be exported: a 404. */
class ExternalPadExportNotFoundException extends ExternalPadException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, self::NOT_FOUND, null, $previous);
	}
}
