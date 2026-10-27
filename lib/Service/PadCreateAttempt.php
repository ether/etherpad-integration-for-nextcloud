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
 * else's; the pad it provisioned; the file a binding write began for.
 *
 * The template flow's pad stays out of the create's rollback:
 * `materializeTemplateInto()` takes it down itself, with an attempt of its
 * own that holds only its pad and the binding write.
 */
class PadCreateAttempt {
	private ?CreatedFileClaim $claim = null;
	private string $padId = '';
	private ?int $bindingAttemptFileId = null;
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

	/**
	 * The file a binding write began for, recorded immediately before
	 * createBinding, which can commit and still throw.
	 *
	 * The id rather than a flag: the rollback needs it to ask about the row,
	 * and reading it off the claim instead would tie that question to the
	 * claim still being owned - a coupling nothing states and a later
	 * disownFile() would quietly break.
	 */
	public function recordBindingAttempt(int $fileId): void {
		$this->bindingAttemptFileId = $fileId;
	}

	/** The file a binding write began for, or null if none did. */
	public function bindingAttemptFileId(): ?int {
		return $this->bindingAttemptFileId;
	}

	public function padId(): string {
		return $this->padId;
	}

	/** Where the file ended up, for the rollback's log lines. */
	public function recordPath(string $path): void {
		$this->path = $path;
	}

	public function path(): string {
		return $this->path;
	}
}
