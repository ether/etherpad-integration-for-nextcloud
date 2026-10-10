<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

final class EtherpadErrorClassifier {
	/**
	 * "It is already gone" — the one Etherpad answer a delete may treat as
	 * success, because retrying it forever would never produce a different
	 * one.
	 *
	 * Both spellings matter. A public pad answers `padID does not exist`,
	 * and a protected one is removed by its group, which answers `groupID
	 * does not exist` — measured against Etherpad rather than read from the
	 * docs. Without the group half, a protected pad whose delete succeeded
	 * but whose response was lost would sit in `pending_delete` for good.
	 */
	public static function isPadAlreadyDeleted(\Throwable $error): bool {
		return self::mentions($error, [
			'padid does not exist',
			'pad does not exist',
			'unknown pad',
			'groupid does not exist',
		]);
	}

	/**
	 * The same idea for a session, and deliberately a different list.
	 *
	 * One shared set of strings would let an answer about a session be read
	 * as an answer about a pad: a pad delete failing while Etherpad tears
	 * down that group's sessions could report `sessionID does not exist`
	 * and be taken for "the pad is gone", which drops a binding row for a
	 * pad that is still there. Measured: a second delete of the same
	 * session answers `sessionID does not exist`.
	 */
	public static function isSessionAlreadyGone(\Throwable $error): bool {
		return self::mentions($error, ['sessionid does not exist']);
	}

	/**
	 * A request that ran out of time on the way - cURL's error 28, as the
	 * HTTP client words it - rather than one Etherpad answered badly, or a
	 * connection it refused.
	 */
	public static function isTimeout(\Throwable $error): bool {
		return self::mentions($error, ['curl error 28', 'timed out']);
	}

	/**
	 * An author Etherpad does not know - one whose id a cache kept after a
	 * reset, say. Measured: listing its sessions answers `authorID does
	 * not exist`.
	 */
	public static function isAuthorUnknown(\Throwable $error): bool {
		return self::mentions($error, ['authorid does not exist']);
	}

	/**
	 * Walk the cause chain for any of these, case-insensitively.
	 *
	 * The chain matters: the client wraps transport errors, so the sentence
	 * Etherpad actually said is often not the one on the outermost
	 * exception.
	 *
	 * @param list<string> $needles lower-case
	 */
	private static function mentions(\Throwable $error, array $needles): bool {
		$current = $error;
		while ($current !== null) {
			$message = strtolower(trim($current->getMessage()));
			if ($message !== '') {
				foreach ($needles as $needle) {
					if (str_contains($message, $needle)) {
						return true;
					}
				}
			}
			$current = $current->getPrevious();
		}

		return false;
	}

	/**
	 * "It is already there" — the one Etherpad answer a create may not treat
	 * as its own doing.
	 *
	 * A create that fails after Etherpad made the pad has to be cleaned up,
	 * or the pad is orphaned the moment the id is forgotten. This is the
	 * case where it must not be: the pad was someone else's before the call,
	 * and deleting it would destroy live content. Measured: Etherpad answers
	 * `padID does already exist`.
	 */
	public static function isPadAlreadyPresent(\Throwable $error): bool {
		return self::mentions($error, ['does already exist']);
	}
}
