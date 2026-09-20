<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

class AdminHealthCheckException extends \RuntimeException {
	public function __construct(
		string $message,
		int $code = 0,
		?\Throwable $previous = null,
		/**
		 * Form field the failure points at, so the settings page can show it
		 * where it can be fixed. Empty when it belongs to no single field.
		 */
		private readonly string $field = '',
		/**
		 * Why it failed, as one of the service's reason constants. Untranslated
		 * and free of anything the admin typed, so it can be logged.
		 */
		private readonly string $reason = '',
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getField(): string {
		return $this->field;
	}

	public function getReason(): string {
		return $this->reason;
	}
}
