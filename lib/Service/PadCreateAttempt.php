<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What one create has made so far, and therefore what a rollback owns.
 *
 * The flows used to answer that with local variables threaded through
 * three closures by reference, which made "is this file mine to delete?"
 * a question about *when* an assignment ran rather than about a fact. Two
 * defects came out of that: a flow that never recorded its file, and a
 * flow that recorded one and then discovered it belonged to somebody else
 * — correct only because a variable was un-set a few lines before the
 * throw.
 *
 * `disownFile()` is now a statement rather than a variable that has to be
 * un-set in the right place. The template flow's pad stays out of the
 * rollback for a different reason: `materializeTemplateInto()` removes its
 * own pad, so that flow records it only to log it.
 */
class PadCreateAttempt {
	private ?CreatedFileClaim $claim = null;
	private string $padId = '';
	private string $path = '';

	/** The file this attempt created, from now until it is disowned. */
	public function claimFile(string $uid, int $fileId, string $expectedBefore = ''): CreatedFileClaim {
		$this->claim = new CreatedFileClaim($uid, $fileId, $expectedBefore);

		return $this->claim;
	}

	/** Found to be somebody else's pad: not ours to write to, nor to remove. */
	public function disownFile(): void {
		$this->claim = null;
	}

	public function recordPad(string $padId): void {
		$this->padId = $padId;
	}

	public function claim(): ?CreatedFileClaim {
		return $this->claim;
	}

	public function padId(): string {
		return $this->padId;
	}

	/**
	 * Where the file ended up, for the rollback's log lines.
	 *
	 * Create-by-parent only learns this after the file exists, and it used
	 * to reach the rollback through a by-reference capture — so a rollback
	 * that ran before the assignment logged an empty path.
	 */
	public function recordPath(string $path): void {
		$this->path = $path;
	}

	public function path(): string {
		return $this->path;
	}
}
