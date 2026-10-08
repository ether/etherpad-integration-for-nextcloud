<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Util\PositiveIntParam;

/**
 * A file or folder id off a request, read strictly (PositiveIntParam) and
 * refused as a bad request. Actions take it as `mixed`: the int Nextcloud
 * would cast it to reads `1e3` as 1000 and `7.5` or `7x` as 7, files the
 * address does not name.
 */
trait ReadsPositiveIds {
	/** $message is translated: it reaches the client as it is. */
	protected function requirePositiveInt(mixed $value, string $message): int {
		try {
			return PositiveIntParam::readRequired($value);
		} catch (\InvalidArgumentException) {
			throw new ControllerBadRequestException($message);
		}
	}
}
