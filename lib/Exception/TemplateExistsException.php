<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A shared template of that name is already stored and the caller did not ask
 * to replace it.
 *
 * Raised by the storage rather than decided beforehand: the check and the
 * write have to be the same step, or two uploads racing each other both pass
 * the check and the second one silently destroys the first.
 */
final class TemplateExistsException extends \RuntimeException {
	public function __construct(string $templateName, ?\Throwable $previous = null) {
		parent::__construct('A shared template already exists: ' . $templateName, 0, $previous);
	}
}
