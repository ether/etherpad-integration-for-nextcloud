<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

class LifecycleException extends \RuntimeException {
	/** What a trash or restore that did not finish throws. Every caller reports it, so it carries its cause. */
	public static function failed(string $flow, \Throwable $cause): self {
		return new self($flow . ' flow failed before completion.', 0, $cause);
	}
}
