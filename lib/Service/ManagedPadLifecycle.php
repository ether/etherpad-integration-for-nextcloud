<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCA\EtherpadNextcloud\Util\PadId;
use OCA\EtherpadNextcloud\Util\SafeError;
use Psr\Log\LoggerInterface;

/**
 * The one place that knows how to bring an Etherpad pad into being, and how
 * to take it away again.
 *
 * A public pad is a pad. A protected pad is a pad inside a group, plus the
 * sessions that grant access to that group — and `deletePad` removes only
 * the first of those three. Every delete in the app used to call it, so a
 * protected pad left its group and every session ever issued for it behind,
 * and nothing collected them afterwards.
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
	 * and never collected.
	 */
	private function provisionGroupPad(string $padName): string {
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
					...SafeError::context($cleanupError),
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
	private function provisionPad(string $padId): void {
		try {
			$this->etherpadClient->createPad($padId);
		} catch (\Throwable $e) {
			if (!EtherpadErrorClassifier::isPadAlreadyPresent($e)) {
				try {
					$this->etherpadClient->deletePad($padId);
				} catch (\Throwable $cleanupError) {
					// Against the rule everywhere else: no binding row was
					// written, so nothing else can name the pad left behind.
					$this->logger->warning('Could not remove the Etherpad pad after its creation failed.', [
						'app' => 'etherpad_nextcloud',
						'padId' => $padId,
						...SafeError::context($cleanupError),
					]);
				}
			}
			throw $e;
		}
	}

	/**
	 * Make the kind of pad an access mode calls for.
	 *
	 * The names come from the caller, because a name says where its pad came
	 * from and that is the caller's business. They arrive unbuilt so that the
	 * branch not taken draws no randomness.
	 *
	 * @param callable():string $padId the id a public pad is created under
	 * @param callable():string $groupPadName the name a protected pad carries
	 * @throws \InvalidArgumentException when $accessMode is not a known mode
	 */
	public function provisionFor(string $accessMode, callable $padId, callable $groupPadName): string {
		$mode = PadAccessMode::tryFrom($accessMode);
		if ($mode === null) {
			throw new \InvalidArgumentException('Unsupported access mode for pad provisioning.');
		}

		// Exhaustive over the enum on purpose: a case added without an arm
		// here is a Psalm error rather than a pad quietly made the wrong way.
		return match ($mode) {
			PadAccessMode::Public => $this->provisionPublicPadId($padId()),
			PadAccessMode::Protected => $this->provisionGroupPad($groupPadName()),
		};
	}

	/**
	 * Make a pad under an id that was chosen here, and hand that id back.
	 *
	 * Two steps rather than one, which is the asymmetry with the group case:
	 * there Etherpad picks the id and the call is the only way to learn it.
	 */
	private function provisionPublicPadId(string $padId): string {
		$this->provisionPad($padId);
		return $padId;
	}

	/**
	 * Put a snapshot into a pad that has just been provisioned.
	 *
	 * setHTML first so formatting survives, and setText only where there is
	 * no HTML or Etherpad refuses it. Never both: `setText` replaces the
	 * content rather than appending, so it would wipe the HTML just
	 * imported. The price is that `getText` afterwards returns what Etherpad
	 * derived from that HTML rather than the string the snapshot held.
	 *
	 * @param array<string,mixed> $context extra keys for the fallback's log
	 *   line; `app` and SafeError's `error`, `error_message` and
	 *   `error_origin` are set here and win a collision. Do not pass a pad
	 *   id - the fileId every caller already supplies is the handle
	 */
	public function seed(string $padId, string $text, string $html, array $context = []): void {
		if (trim($html) !== '') {
			try {
				$this->etherpadClient->setHTML($padId, $html);
				return;
			} catch (\Throwable $htmlError) {
				// Every caller supplies a fileId in $context, which is the
				// handle to keep: a pad id plus the configured host is a
				// working link into a public pad.
				$this->logger->warning('Could not import the HTML snapshot; falling back to plain text.', [
					'app' => 'etherpad_nextcloud',
					...SafeError::context($htmlError),
				] + $context);
			}
		}

		$this->etherpadClient->setText($padId, $text);
	}

	/**
	 * Remove a pad this request has just provisioned, without asking what
	 * its group holds: `provisionGroupPad` made the group earlier in the same
	 * call, with the one pad that call put in it, so control flow has
	 * answered the ownership question `discardIfPresent` asks Etherpad.
	 * Asking would only make it breakable: these are rollbacks, nothing
	 * retries them, and a `listPads` that timed out would give up the group
	 * and its sessions for good.
	 *
	 * Only for a pad provisioned in the same call. Anything read back out of
	 * a binding goes through `discardIfPresent`.
	 *
	 * What that rule protects: a group pad id names a whole Etherpad group,
	 * and this deletes the group, not the pad. For a pad this app made, the
	 * group holds nothing else. For an adopted one - the legacy Ownpad
	 * migration, and any import built on it - the group is Ownpad's and holds
	 * other people's pads, so calling this on one destroys them. Nothing here
	 * can tell the two apart; the caller has to know which it has.
	 */
	public function discardProvisioned(string $padId): void {
		$groupId = PadId::groupIdOf($padId);
		if ($groupId === null) {
			$this->etherpadClient->deletePad($padId);
			return;
		}

		$this->etherpadClient->deleteGroup($groupId);
	}

	/**
	 * Whether the pad a file's snapshot was taken from still exists, as far
	 * as Etherpad will say. Only its own answer that there is no such pad
	 * counts as absent; a request that got no answer is unknown, because
	 * the pad may well be there.
	 *
	 * Revisions only grow, so a pad under that id with fewer of them than
	 * the snapshot was taken at is behind: created again since, empty, or
	 * brought back from an older backup. A snapshot revision of -1, from a
	 * file never synced, says nothing, and any pad counts as present.
	 *
	 * An unknown answer is logged here, with its cause, since no caller
	 * gets to see the exception it came from. So is a pad behind, with its
	 * id: someone may have written into it since it came back, so every
	 * caller leaves it in place, and this line is the last record of where
	 * it is.
	 *
	 * @param array<string,mixed> $context what the log line should carry, fileId above all
	 */
	public function presenceOf(string $padId, int $snapshotRevision = -1, array $context = [], ?int $timeoutSeconds = null): PadPresence {
		return $this->probe($padId, $snapshotRevision, $context, $timeoutSeconds)->presence;
	}

	/**
	 * presenceOf(), with the revision count the answer came from.
	 *
	 * @param array<string,mixed> $context what the log line should carry, fileId above all
	 */
	public function probe(string $padId, int $snapshotRevision = -1, array $context = [], ?int $timeoutSeconds = null): PadProbe {
		try {
			$revisions = $this->etherpadClient->getRevisionsCount($padId, $timeoutSeconds);
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				return new PadProbe(PadPresence::Absent, null);
			}
			$this->logger->warning('Could not ask Etherpad whether a pad still exists.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			] + $context);
			return new PadProbe(PadPresence::Unknown, null);
		}
		if ($revisions < $snapshotRevision) {
			$this->logger->warning('A pad has fewer revisions than its file\'s snapshot and is no longer the file\'s. It is left in place.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
			] + $context);
			return new PadProbe(PadPresence::Behind, $revisions);
		}
		return new PadProbe(PadPresence::Present, $revisions);
	}

	/**
	 * Remove a pad the app is bound to, whatever kind it is: true when this
	 * call removed something - the pad, or the empty group a protected pad
	 * left behind - and false when nothing was left to remove, because
	 * Etherpad says the pad does not exist, or its group does not (a group
	 * that is not there cannot hold the pad either). Every caller reads
	 * false as done; what differs between them is what they do when the
	 * delete fails, and every other error is theirs.
	 *
	 * $knownAbsent: Etherpad has just said there is no such pad. A public
	 * pad leaves nothing else behind, so that takes no call; a protected
	 * one can leave its group, which is still looked for.
	 *
	 * $budget: a sweep's run; each call gets what is left, and one that
	 * would not finish is not made. Nothing is removed before the last
	 * call, so stopping leaves the pad as it was.
	 *
	 * $retried: the caller keeps its row when this throws, and tries again.
	 * A group that cannot be read then fails the call, with nothing
	 * removed, instead of being given up for the pad alone: the next try
	 * may read it, and a group given up is never looked at again.
	 */
	public function discardIfPresent(string $padId, ?RunBudget $budget = null, bool $knownAbsent = false, bool $retried = false): bool {
		if ($knownAbsent && !PadId::isGroupPad($padId)) {
			return false;
		}
		try {
			$this->discard($padId, $budget, $retried);
			return true;
		} catch (\Throwable $e) {
			if (EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * Remove a pad, whatever kind it is. Its group goes only once Etherpad
	 * has confirmed it holds this pad alone, or nothing: a binding's pad id
	 * need not name a group this app made (docs/etherpad-integration.md,
	 * "Removing a pad").
	 */
	private function discard(string $padId, ?RunBudget $budget, bool $retried): void {
		$groupId = PadId::groupIdOf($padId);
		if ($groupId === null) {
			$this->etherpadClient->deletePad($padId, RunBudget::timeoutOf($budget));
			return;
		}

		$pads = $this->padsInGroup($groupId, $padId, RunBudget::timeoutOf($budget), $retried);
		// An empty group counts too, and it is the only way the pads deleted
		// before this existed are ever collected: their group is still there
		// with nothing in it, and a retry that only deleted the pad again
		// would leave it standing for good. A group holding no pads has no
		// content to lose, and its sessions grant access to nothing.
		if ($pads !== null && ($pads === [] || $pads === [$padId])) {
			$this->etherpadClient->deleteGroup($groupId, RunBudget::timeoutOf($budget));
			// Worth a line: this removed a group, its pad and every session
			// issued for it, and an admin tracing a vanished pad has nothing
			// else to go on.
			// The pad id stays here and in the two lines below: these are
			// group pads, and a group pad's url opens for nobody without a
			// session, so it is a name rather than a way in.
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
		$this->etherpadClient->deletePad($padId, RunBudget::timeoutOf($budget));
	}

	/**
	 * What the group holds, or nothing when that cannot be found out.
	 *
	 * Reading the group is what makes removing the *group* safe. It is not
	 * what makes removing the *pad* safe — that was a plain `deletePad`
	 * before any of this. So a read that fails for its own reasons does not
	 * veto the delete: not knowing gives up the group and keeps the pad
	 * delete, which is the half that was always safe. The group then stays
	 * behind, empty once the pad is gone, and nothing leads back to it; its
	 * sessions open nothing. Only a caller that will try again ($retried)
	 * gets the failed read instead, to report, and loses nothing.
	 *
	 * An answer that the group does not exist is no failed read: it goes to
	 * the caller as it came.
	 *
	 * @return list<string>|null null when the group could not be read
	 */
	private function padsInGroup(string $groupId, string $padId, ?int $timeoutSeconds, bool $retried): ?array {
		try {
			return $this->etherpadClient->listPads($groupId, $timeoutSeconds);
		} catch (\Throwable $e) {
			if ($retried || EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				throw $e;
			}
			$this->logger->warning('Could not read the Etherpad group; removing only the pad.', [
				'app' => 'etherpad_nextcloud',
				'padId' => $padId,
				'groupId' => $groupId,
				...SafeError::context($e),
			]);
			return null;
		}
	}
}
