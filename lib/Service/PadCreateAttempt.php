<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What one create has made so far, and therefore what its rollback owns:
 * the file it claimed, until it disowns one that turns out to be somebody
 * else's.
 */
class PadCreateAttempt {
	private ?CreatedFileClaim $claim = null;
	private string $path = '';

	/** The file this attempt created, from now until it is disowned. */
	public function claimFile(string $uid, int $fileId): CreatedFileClaim {
		$this->claim = new CreatedFileClaim($uid, $fileId);

		return $this->claim;
	}

	/** Found to be somebody else's pad: not ours to write to, nor to remove. */
	public function disownFile(): void {
		$this->claim = null;
	}

	public function claim(): ?CreatedFileClaim {
		return $this->claim;
	}

	/** Where the file ended up, for the create's answer and the rollback's log lines. */
	public function recordPath(string $path): void {
		$this->path = $path;
	}

	public function path(): string {
		return $this->path;
	}
}
