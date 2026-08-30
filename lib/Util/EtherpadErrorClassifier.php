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
	 * but whose response was lost would sit in `pending_delete` and fail
	 * every retry for good.
	 */
	public static function isPadAlreadyDeleted(\Throwable $error): bool {
		$current = $error;
		while ($current !== null) {
			$message = strtolower(trim($current->getMessage()));
			if (
				$message !== '' && (
					str_contains($message, 'padid does not exist')
					|| str_contains($message, 'pad does not exist')
					|| str_contains($message, 'unknown pad')
					|| str_contains($message, 'groupid does not exist')
				)
			) {
				return true;
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
		$current = $error;
		while ($current !== null) {
			$message = strtolower(trim($current->getMessage()));
			if ($message !== '' && str_contains($message, 'does already exist')) {
				return true;
			}
			$current = $current->getPrevious();
		}

		return false;
	}
}
