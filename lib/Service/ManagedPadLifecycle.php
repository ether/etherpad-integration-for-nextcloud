<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
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
	/** How long whether a pad is lost may take to answer (howLost()). */
	public const PROBE_TIMEOUT_SECONDS = 3;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create a group holding exactly one pad. If the pad cannot be created,
	 * the group is taken down again, as far as Etherpad lets it, before the
	 * failure is thrown on.
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
	 * Create a pad that stands on its own. If the call fails after Etherpad
	 * has made it, the pad is taken down again, as far as Etherpad lets it.
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
	 * Put a snapshot into a pad that has just been provisioned, and say how
	 * many revisions the pad has then: what a file holding that snapshot
	 * records as synced (`snapshot_rev`). A count above 0 makes the file
	 * hold saved content should Etherpad lose the pad (howLost()), whatever
	 * text it saved; at 0 only its text would. -1 when Etherpad does not
	 * say: the file is left to its first sync, as a new one is.
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
	 * @return int the pad's revisions once seeded, -1 when not known
	 */
	public function seed(string $padId, string $text, string $html, array $context = []): int {
		if (trim($html) !== '') {
			try {
				$this->etherpadClient->setHTML($padId, $html);
				return $this->revisionsOfSeeded($padId);
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
		return $this->revisionsOfSeeded($padId);
	}

	private function revisionsOfSeeded(string $padId): int {
		try {
			return $this->etherpadClient->getRevisionsCount($padId);
		} catch (\Throwable) {
			return -1;
		}
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
	 * How Etherpad has lost the pad a file's row names, or null when it has
	 * not:
	 * - Absent when it has no pad under that id: a protected pad, whose
	 *   session would open nothing, or a public pad whose file holds saved
	 *   content.
	 * - Behind when it has one without a single revision while the file
	 *   holds saved content, and the pad's text is not the text the file
	 *   saved: a public pad Etherpad made anew when someone
	 *   visited its address - with its default text, and the visitor as
	 *   its author. A pad at revision 0 holding the saved text had its
	 *   history cut short in Etherpad, and nothing is lost.
	 *
	 * A public pad with nothing saved in its file - an Ownpad link to a pad
	 * nobody opened yet, say - Etherpad makes on the first visit, as ever,
	 * and is never lost, so Etherpad is not asked about it. A pad merely
	 * behind the snapshot is not lost: files a restore in 1.1.0-beta.1 left
	 * kept the old pad's revision count. A pad an admin made anew through
	 * the API with other text than the file saved counts as made anew; the
	 * recovery leaves it in place. Quiet, unlike probe(): an open asks this
	 * every time.
	 *
	 * Each question to Etherpad waits PROBE_TIMEOUT_SECONDS at most - the
	 * revision count, and at revision 0 the text: an open that may write,
	 * and a restore, wait on the answers.
	 *
	 * @param string $padId the pad the row names, and its access mode: a row's, not always the file's
	 * @param ParsedPadFile $pad the file, for its snapshot's revision and
	 *                           text. The file and not the text as a string:
	 *                           this method is in the trace of whatever
	 *                           Etherpad throws here.
	 * @throws \Throwable when Etherpad gives any other answer, or none
	 */
	public function howLost(string $padId, string $accessMode, ParsedPadFile $pad): ?PadPresence {
		if (!self::holdsSavedContent($accessMode, $pad)) {
			return null;
		}
		try {
			$revisions = $this->etherpadClient->getRevisionsCount($padId, self::PROBE_TIMEOUT_SECONDS);
		} catch (\Throwable $e) {
			if (!EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				throw $e;
			}
			return PadPresence::Absent;
		}
		// The text is asked only where it decides.
		if ($revisions !== 0 || !self::savedAnything($pad)) {
			return null;
		}
		return self::isMadeAnew($revisions, $this->etherpadClient->getText($padId, self::PROBE_TIMEOUT_SECONDS), $pad) ? PadPresence::Behind : null;
	}

	/**
	 * Whether a pad at $revisions holding $padText is one Etherpad made anew
	 * in place of the file's (howLost()'s Behind): without a single revision
	 * while the file holds saved content, and with other text than the file
	 * saved. For a caller that has the pad's text at hand already.
	 */
	public static function isMadeAnew(int $revisions, string $padText, ParsedPadFile $pad): bool {
		return $revisions === 0 && self::savedAnything($pad) && !self::sameText($padText, $pad->savedText());
	}

	/** Text as Etherpad and a file hold it, but for line endings and trailing whitespace. */
	private static function sameText(string $a, string $b): bool {
		$normalize = static fn (string $text): string => rtrim(str_replace("\r\n", "\n", $text));
		return $normalize($a) === $normalize($b);
	}

	/**
	 * Whether an open should stop because the pad is lost: only on a
	 * definite answer. Etherpad slow, silent or refusing the question opens
	 * the pad as before - the check is for a rare case, and must not make
	 * an open depend on it; whatever is wrong with Etherpad shows there.
	 */
	public function isKnownLost(string $padId, string $accessMode, ParsedPadFile $pad): bool {
		try {
			return $this->howLost($padId, $accessMode, $pad) !== null;
		} catch (EtherpadClientException $e) {
			// Etherpad's own answer or silence: expected now and then.
			$this->logger->debug('Could not ask Etherpad whether a pad is lost; opened as before.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			return false;
		} catch (\Throwable $e) {
			// Anything else is a fault here, not Etherpad's: the open goes on,
			// but the check is not doing its job, so it is said out loud.
			$this->logger->warning('Could not tell whether a pad is lost; opened as before.', [
				'app' => 'etherpad_nextcloud',
				...SafeError::context($e),
			]);
			return false;
		}
	}

	/**
	 * Whether losing the pad would lose anything the file holds: always for
	 * a protected pad, whose session opens nothing without it, and for a
	 * public pad once its file has saved content.
	 */
	public static function holdsSavedContent(string $accessMode, ParsedPadFile $pad): bool {
		return $accessMode === BindingService::ACCESS_PROTECTED || self::savedAnything($pad);
	}

	/**
	 * Whether the file has saved content: a snapshot taken past a pad's
	 * first revision (`snapshot_rev` above 0), or any text at all - a file
	 * made from a template by 1.1.0-beta.1 holds its content at
	 * `snapshot_rev: 0`, and a new file holds none.
	 */
	private static function savedAnything(ParsedPadFile $pad): bool {
		return $pad->snapshotRev > 0 || trim($pad->savedText()) !== '';
	}

	/**
	 * Whether a group holding $pads holds nothing but $padIds: it holds
	 * them alone, or nothing. A legacy `.pad` names its own pad id, and its
	 * group may be someone else's, with other pads in it that the group's
	 * deletion or sessions would take along (docs/etherpad-integration.md,
	 * "Removing a pad").
	 *
	 * @param list<string> $pads
	 * @param list<string> $padIds
	 */
	public static function groupHoldsOnly(array $pads, array $padIds): bool {
		return array_diff($pads, $padIds) === [];
	}

	/**
	 * Whether Etherpad answers at all, asked within $budget: a failure that
	 * reads as Etherpad unreachable - an HTTP error, an answer it could not
	 * have meant - may be one pad's alone.
	 *
	 * @throws RunBudgetSpentException
	 */
	public function answers(?RunBudget $budget = null): bool {
		$timeout = RunBudget::timeoutOf($budget);
		try {
			$this->etherpadClient->assertAnswering($timeout);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Remove a pad the app is bound to, whatever kind it is - the pad, or
	 * the empty group a protected pad left behind. Etherpad saying the pad
	 * does not exist, or its group does not (a group that is not there
	 * cannot hold the pad either), counts as done; what differs between the
	 * callers is what they do when the delete fails, and every other error
	 * is theirs.
	 *
	 * $knownAbsent: Etherpad has just said there is no such pad. A public
	 * pad leaves nothing else behind, so that takes no call; a protected
	 * one can leave its group, which goes only while it holds nothing. The
	 * pad may be back by then - made anew through the API - and is not this
	 * call's to remove; a group that cannot be read stays, and the reason
	 * reaches the caller.
	 *
	 * $budget: a sweep's run, or an open's few seconds; each call gets what
	 * is left, and one that would not finish is not made. Nothing is
	 * removed before the last call, so stopping leaves the pad as it was.
	 *
	 * $retried: the caller keeps its row when this throws, and tries again.
	 * A group that cannot be read then fails the call, with nothing
	 * removed, instead of being given up for the pad alone: the next try
	 * may read it, and a group given up is never looked at again.
	 */
	public function discardIfPresent(string $padId, ?RunBudget $budget = null, bool $knownAbsent = false, bool $retried = false): void {
		$groupId = PadId::groupIdOf($padId);
		try {
			if (!$knownAbsent) {
				$this->discard($padId, $budget, $retried);
			} elseif ($groupId !== null && $this->etherpadClient->listPads($groupId, RunBudget::timeoutOf($budget)) === []) {
				$this->etherpadClient->deleteGroup($groupId, RunBudget::timeoutOf($budget));
			}
		} catch (\Throwable $e) {
			if (!EtherpadErrorClassifier::isPadAlreadyDeleted($e)) {
				throw $e;
			}
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
		if ($pads !== null && self::groupHoldsOnly($pads, [$padId])) {
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
