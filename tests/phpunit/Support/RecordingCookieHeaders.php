<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Support;

use OCA\EtherpadNextcloud\Http\CookieHeaders;

/** CookieHeaders that keeps what it would send, each line with whether it replaces. */
final class RecordingCookieHeaders extends CookieHeaders {
	/** @var list<array{string, bool}> */
	public array $sent = [];

	protected function send(string $cookieLine, bool $replace): void {
		$this->sent[] = [$cookieLine, $replace];
	}
}
