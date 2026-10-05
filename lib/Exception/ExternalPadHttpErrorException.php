<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/** The other server answered with an HTTP error, and its status goes along. */
class ExternalPadHttpErrorException extends ExternalPadException {
	public function __construct(string $message, private readonly int $httpStatus, ?\Throwable $previous = null) {
		parent::__construct($message, self::HTTP_ERROR, $previous);
	}

	public function httpStatus(): int {
		return $this->httpStatus;
	}
}
