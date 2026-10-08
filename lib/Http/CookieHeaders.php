<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Http;

/**
 * A Set-Cookie header sent beside the ones Nextcloud sends in the same
 * request, not over them.
 *
 * A response's own headers go out through PHP's `header()`, which
 * replaces every Set-Cookie sent before it: the session cookie Nextcloud
 * sets when it starts or renews a session, and the renewal of a
 * remembered login. An Etherpad session cookie handed to the response
 * took their place, and the browser never got them. CookieHeadersTest
 * holds every other class under lib to sending none.
 */
class CookieHeaders {
	/** Sends the line, but for an empty one: an open without a session has none. */
	public function add(string $setCookie): void {
		if ($setCookie === '') {
			return;
		}
		$this->send('Set-Cookie: ' . $setCookie, false);
	}

	/** PHP's `header()`, which a test replaces to see what is sent. */
	protected function send(string $cookieLine, bool $replace): void {
		\header($cookieLine, $replace);
	}
}
