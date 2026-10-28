<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

class PublicPadOpenTarget {
	public function __construct(
		/** The pad's addresses and a live session, private as in PadOpenTarget. */
		private readonly string $url,
		private readonly string $originalPadUrl,
		private readonly string $cookieHeader,
		public readonly bool $isReadOnlyView,
	) {
	}

	public function cookieHeader(): string {
		return $this->cookieHeader;
	}

	public function url(): string {
		return $this->url;
	}

	public function originalPadUrl(): string {
		return $this->originalPadUrl;
	}
}
