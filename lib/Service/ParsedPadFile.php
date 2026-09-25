<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;

/**
 * Result of `PadFileService::readPad()`. Wraps the 3-step
 * `parsePadFile + extractPadMetadata + isExternalFrontmatter` read that
 * used to be open-coded at every caller. Most call sites only need the
 * derived flat fields (`padId`, `accessMode`, `padUrl`, `isExternal`);
 * `$frontmatter` + `$body` are exposed for the few sites that touch other
 * frontmatter keys or need to inspect the body block.
 */
class ParsedPadFile {
	public function __construct(
		/** @var array<string,mixed> */
		public readonly array $frontmatter,
		public readonly string $body,
		public readonly string $padId,
		public readonly string $accessMode,
		public readonly string $padUrl,
		public readonly bool $isExternal,
		public readonly int $snapshotRev,
	) {
	}

	/**
	 * Whether the file names a pad on another Etherpad server: by its
	 * frontmatter, or by an `ext.` pad id alone, which one with incomplete
	 * metadata still has. No such pad is the app's to bind, seed or delete.
	 */
	public function namesAnExternalPad(): bool {
		return $this->isExternal || str_starts_with($this->padId, 'ext.');
	}

	/**
	 * The link of a pad on another server ($isExternal), as the file has it:
	 * the one rule every way of opening, reading and syncing such a pad
	 * holds it to. A file that says too little to reach it - no link, or a
	 * protected pad there - is the link's problem, and its reason is the
	 * user's only hint.
	 *
	 * @throws ExternalPadException
	 */
	public function externalPadUrl(): string {
		if ($this->accessMode !== BindingService::ACCESS_PUBLIC) {
			throw new ExternalPadException('External pad metadata requires public access_mode.');
		}
		if ($this->padUrl === '') {
			throw new ExternalPadException('External pad URL metadata is missing or invalid.');
		}
		return $this->padUrl;
	}
}
