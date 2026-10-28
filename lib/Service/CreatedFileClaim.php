<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Identity, expected content and write evidence for one create or first init.
 *
 * Pass the same instance through writing and recovery so a later failure
 * retains the write evidence. A claim does not lock the file.
 */
class CreatedFileClaim {
	public ?string $writtenHash = null;

	public function __construct(
		public readonly string $uid,
		public readonly int $fileId,
		/**
		 * Empty for an API create; what Nextcloud copied in for a template
		 * or the external tile; what a first init read. Private behind its
		 * method, for the reason given at ParsedPadFile: it is a document.
		 */
		private readonly string $expectedBefore = '',
	) {
	}

	public function expectedBefore(): string {
		return $this->expectedBefore;
	}
}
