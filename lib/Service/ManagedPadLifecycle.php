<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
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
	 * Create a pad that stands on its own, and leave nothing behind if the
	 * call fails after Etherpad has already made it.
	 *
	 * The id is chosen here rather than by Etherpad, so unlike the group
	 * case there is always something to clean up with — which is what makes
	 * this worth doing: a `createPad` that times out on the way back leaves
	 * a pad whose id the caller never learns.
	 *
	 * The one failure that is not ours to undo is a pad that was already
	 * there. Random ids make that vanishingly unlikely, but the cost of
	 * being wrong is deleting someone's live pad, so it is worth the check.
	 */
	public function provisionPad(string $padId): void {
		try {
			$this->etherpadClient->createPad($padId);
		} catch (\Throwable $e) {
			if (!EtherpadErrorClassifier::isPadAlreadyPresent($e)) {
				try {
					$this->etherpadClient->deletePad($padId);
				} catch (\Throwable $cleanupError) {
					$this->logger->warning('Could not remove the Etherpad pad after its creation failed.', [
						'app' => 'etherpad_nextcloud',
						'padId' => $padId,
						'exception' => $cleanupError,
					]);
				}
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
	 * being deleted — or nothing at all — has nothing else to lose.
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

		$pads = $this->padsInGroup($groupId, $padId);
		// An empty group counts too, and it is the only way the pads deleted
		// before this existed are ever collected: their group is still there
		// with nothing in it, and a retry that only deleted the pad again
		// would leave it standing for good. A group holding no pads has no
		// content to lose, and its sessions grant access to nothing.
		if ($pads !== null && ($pads === [] || $pads === [$padId])) {
			$this->etherpadClient->deleteGroup($groupId);
			// Worth a line: this removed a group, its pad and every session
			// issued for it, and an admin tracing a vanished pad has nothing
			// else to go on.
			$this->logger->debug('Removed the Etherpad group holding a protected pad.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
				'groupId' => $groupId,
				'padsInGroup' => count($pads),
			]);
			return;
		}

		// Either something else lives in that group — an Ownpad-era group
		// with more than one pad, or a group that was never ours — or the
		// question could not be answered. Both mean the same thing here:
		// take the pad only, which is what every delete did before.
		$this->logger->debug('Removing only the pad, not its Etherpad group.', [
			'app' => 'etherpad_nextcloud',
			'padId' => $padId,
			'groupId' => $groupId,
			'padsInGroup' => $pads === null ? 'unknown' : count($pads),
		]);
		$this->etherpadClient->deletePad($padId);
	}

	/**
	 * What the group holds, or nothing when that cannot be found out.
	 *
	 * Reading the group is what makes removing the *group* safe. It is not
	 * what makes removing the *pad* safe — that was a plain `deletePad`
	 * before any of this. So a read that fails for its own reasons must not
	 * veto the delete: most callers here are rollbacks with no retry behind
	 * them, and a single timed-out read would strand the very pad they exist
	 * to reclaim. Not knowing gives up the group and keeps the pad delete,
	 * which is the half that was always safe.
	 *
	 * `groupID does not exist` is different: it is an answer, not a failure,
	 * and the callers already know how to read it.
	 *
	 * @return list<string>|null null when the group could not be read
	 */
	private function padsInGroup(string $groupId, string $padId): ?array {
		try {
			return $this->etherpadClient->listPads($groupId);
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				throw $e;
			}
			$this->logger->warning('Could not read the Etherpad group; removing only the pad.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
				'groupId' => $groupId,
				'exception' => $e,
			]);
			return null;
		}
	}
}
