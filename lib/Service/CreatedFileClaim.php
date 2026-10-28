<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Identity, expected content and write evidence of a claim on one `.pad`
 * file.
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
		 * What the file held when it was claimed: nothing for an API
		 * create's new file, what Nextcloud copied in for a template or the
		 * external tile, what a first init read. Private, for the reason
		 * given at ParsedPadFile: it is a document.
		 */
		private readonly string $expectedBefore = '',
	) {
	}

	/**
	 * Whether a file holding $content is still as this claim left it.
	 * Before a recorded write, only the content the claim expected matches;
	 * after it, only content with the recorded hash, as another create's
	 * document may name the same file id. A written file that is emptied,
	 * or holds the expected content again, does not match. Before a write,
	 * a competing create's empty file cannot be told from this one's.
	 */
	public function holds(string $content): bool {
		return $this->writtenHash === null
			? $content === $this->expectedBefore
			: hash_equals($this->writtenHash, hash('sha256', $content));
	}
}
