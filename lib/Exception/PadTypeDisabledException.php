<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * Raised when a pad of a given access mode is requested but the admin has
 * switched that pad type off. Existing pads of the type keep working — this
 * only guards creation.
 *
 * The access mode is carried as data rather than baked into prose: the
 * exception message stays internal (logs), and the controller error mapper
 * builds the client-facing payload from `getAccessMode()`.
 */
class PadTypeDisabledException extends \RuntimeException {
	/** @param string $accessMode the disabled mode, or '' when no type is enabled at all */
	public function __construct(
		private readonly string $accessMode = '',
	) {
		parent::__construct($accessMode !== ''
			? 'Pad type is disabled: ' . $accessMode
			: 'No pad type is enabled.');
	}

	public function getAccessMode(): string {
		return $this->accessMode;
	}
}
