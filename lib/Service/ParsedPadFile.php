<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

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
		/**
		 * Pad this file defers to instead of carrying a binding of its own,
		 * or '' for the ordinary case. Set on a copy so that opening it lands
		 * on the original rather than on the recovery card.
		 */
		public readonly string $aliasOfPadId = '',
	) {
	}
}
