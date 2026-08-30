<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * The one place that knows how to take an Etherpad pad away again.
 *
 * A public pad is a pad. A protected pad is a pad inside a group, plus the
 * sessions that grant access to that group — and `deletePad` removes only
 * the first of those three. Every delete in the app used to call it, so a
 * protected pad left its group and every session ever issued for it behind,
 * and nothing collected them afterwards.
 *
 * @psalm-api
 */
class ManagedPadLifecycle {
	/**
	 * `g.<16 chars>$<name>` is the shape Etherpad gives a group pad, and the
	 * prefix before the `$` is the group. The id is the authority here rather
	 * than the binding's access mode: it is what Etherpad itself keys on, and
	 * it is still right for a pad whose binding says something else after a
	 * migration.
	 */
	private const GROUP_PAD_PATTERN = '/^(g\.[A-Za-z0-9]{16})\$/';

	public function __construct(
		private EtherpadClient $etherpadClient,
	) {
	}

	/**
	 * Remove a pad the app provisioned, whatever kind it is.
	 *
	 * One group holds exactly one pad here — provisionPadId() creates the
	 * group and the pad together — so deleting the group cannot take a
	 * bystander with it.
	 *
	 * An id in neither shape is treated as a plain pad, which is what the
	 * app did for all of them before: a legacy Ownpad id is deleted the way
	 * it always was rather than being refused.
	 */
	public function discard(string $padId): void {
		$groupId = $this->groupIdOf($padId);
		if ($groupId !== null) {
			$this->etherpadClient->deleteGroup($groupId);
			return;
		}
		$this->etherpadClient->deletePad($padId);
	}

	/** The group a protected pad belongs to, or null for anything else. */
	public function groupIdOf(string $padId): ?string {
		return preg_match(self::GROUP_PAD_PATTERN, $padId, $matches) === 1 ? $matches[1] : null;
	}
}
