<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

class EtherpadClientException extends \RuntimeException {
	/**
	 * $e is this instance's Etherpad not reachable: no connection, an HTTP
	 * error, an answer it could not have meant, no address or key to ask
	 * with. Trying again may help. What the answer says and what the log
	 * makes of it both go by this.
	 */
	public static function isEtherpadUnreachable(\Throwable $e): bool {
		return $e instanceof self && $e->meansEtherpadUnreachable();
	}

	/** Yes, unless a subclass means something else: see isEtherpadUnreachable(). */
	public function meansEtherpadUnreachable(): bool {
		return true;
	}
}
