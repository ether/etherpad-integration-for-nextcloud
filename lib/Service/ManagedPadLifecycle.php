<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PadId;
use Psr\Log\LoggerInterface;

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
	public function __construct(
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create a group holding exactly one pad, and leave nothing behind if
	 * the pad cannot be created.
	 *
	 * The two steps are separate calls, so a failure between them strands a
	 * group that nothing will ever look at again — invisible from Nextcloud
	 * and never collected. Both provisioning paths, the first open of a
	 * `.pad` and a restore from the trash, had that gap and the same cleanup
	 * written out twice.
	 */
	public function provisionGroupPad(string $padName): string {
		$groupId = $this->etherpadClient->createGroup();
		try {
			return $this->etherpadClient->createGroupPad($groupId, $padName);
		} catch (\Throwable $e) {
			try {
				$this->etherpadClient->deleteGroup($groupId);
			} catch (\Throwable $cleanupError) {
				$this->logger->warning('Could not remove the Etherpad group after its pad failed to be created.', [
					'app' => 'etherpad_nextcloud',
					'groupId' => $groupId,
					'exception' => $cleanupError,
				]);
			}
			throw $e;
		}
	}

	/**
	 * Remove a pad the app is bound to, whatever kind it is.
	 *
	 * The group is only removed once Etherpad has confirmed it holds nothing
	 * but this pad. The id alone cannot carry that: a binding's pad id does
	 * not have to be one this app provisioned. A legacy Ownpad `.pad` file
	 * names its own pad id, and the migration binds it as given — so a file
	 * written by hand naming `g.<someone-elses-group>$anything` would, on a
	 * plain shape check, have made deleting that file destroy another user's
	 * group, their pad and their sessions. Asking first turns a guess about
	 * ownership into a fact about content: a group that holds only the pad
	 * being deleted has nothing else to lose.
	 *
	 * A group that is not there at all surfaces as Etherpad's `groupID does
	 * not exist`, which the callers already read as "already gone" — correct
	 * here, since a pad inside a group that does not exist cannot exist
	 * either.
	 */
	public function discard(string $padId): void {
		$groupId = PadId::groupIdOf($padId);
		if ($groupId === null) {
			$this->etherpadClient->deletePad($padId);
			return;
		}

		if ($this->etherpadClient->listPads($groupId) === [$padId]) {
			$this->etherpadClient->deleteGroup($groupId);
			// Worth a line: this removed a group, its pad and every session
			// issued for it, and an admin tracing a vanished pad has nothing
			// else to go on.
			$this->logger->debug('Removed the Etherpad group holding a protected pad.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
				'groupId' => $groupId,
			]);
			return;
		}

		// Something else lives in that group: an Ownpad-era group with more
		// than one pad, or a group that was never ours. Take the pad only,
		// which is what every delete did before.
		$this->logger->debug('Removing only the pad: its Etherpad group holds more than this pad.', [
			'app' => 'etherpad_nextcloud',
			'padId' => $padId,
			'groupId' => $groupId,
		]);
		$this->etherpadClient->deletePad($padId);
	}
}
