<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Outcome of an authenticated `.pad` open. Kept separate from
 * `PublicPadOpenTarget`: the internal flow always carries the bound
 * `pad_id` / `access_mode` and the file's userspace path, while the
 * public flow answers a share token and has none of those fields.
 */
class PadOpenTarget {
	public function __construct(
		public readonly string $file,
		public readonly int $fileId,
		public readonly string $padId,
		public readonly string $accessMode,
		/**
		 * The pad's addresses: one of a pad on another server opens it to
		 * whoever has it. Private behind their methods, as the cookie below.
		 */
		private readonly string $padUrl,
		public readonly bool $isExternal,
		private readonly string $originalPadUrl,
		private readonly string $url,
		/**
		 * The Set-Cookie line that opens a protected pad: a live session.
		 * Private behind its method, for the reason given at ParsedPadFile -
		 * a public field of an argument ends up in a serialized trace.
		 */
		private readonly string $cookieHeader,
		// Required, unlike every optional convenience above it: these decide
		// whether somebody may edit, and a default would let a future
		// construction site grant that by forgetting.
		//
		// Two fields, because they are two things. A public pad opened
		// read-only is Etherpad's own read-only view in an iframe, not this
		// app's viewer — but it is still not writable, and anything that
		// follows from "may not write" has to follow from that rather than
		// from which surface is shown.
		public readonly bool $isReadOnlyView,
		public readonly bool $mayWrite,
	) {
	}

	public function cookieHeader(): string {
		return $this->cookieHeader;
	}

	public function padUrl(): string {
		return $this->padUrl;
	}

	public function originalPadUrl(): string {
		return $this->originalPadUrl;
	}

	public function url(): string {
		return $this->url;
	}
}
