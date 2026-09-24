<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadPresence;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A protected pad is a pad inside a group plus the sessions that grant
 * access to it. deletePad removes one of those three, and nothing else in
 * the app ever collected the rest — but the group may only be removed once
 * Etherpad has confirmed it holds nothing else.
 */
class ManagedPadLifecycleTest extends TestCase {
	private function lifecycle(EtherpadClient $client): ManagedPadLifecycle {
		return new ManagedPadLifecycle($client, $this->createMock(LoggerInterface::class));
	}

	private function provisionPublic(EtherpadClient $client, string $padId): string {
		return $this->lifecycle($client)->provisionFor(
			BindingService::ACCESS_PUBLIC,
			static fn (): string => $padId,
			static fn (): string => 'p-unused',
		);
	}

	public function testDeletesTheWholeGroupWhenItHoldsOnlyThisPad(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abc123';
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('listPads')->with('g.ABCDEFGHIJKLMNOP')->willReturn([$padId]);
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->never())->method('deletePad');

		$this->assertTrue($this->lifecycle($client)->discardIfPresent($padId));
	}

	/**
	 * The one that matters: a binding's pad id need not name a group this app
	 * created. A legacy Ownpad `.pad` names its own pad id and the migration
	 * binds it as given, so a hand-written file naming someone else's group
	 * would otherwise have made deleting that file destroy their group, their
	 * pad and their sessions. A group of ours that holds more than this pad
	 * keeps the rest the same way.
	 */
	public function testLeavesAGroupAloneWhenItHoldsSomethingElse(): void {
		$cases = [
			'someone else\'s group' => ['g.VICTIMGROUPID12$made-up', ['g.VICTIMGROUPID12$their-real-pad']],
			'more than this pad' => ['g.ABCDEFGHIJKLMNOP$p-abc123', ['g.ABCDEFGHIJKLMNOP$p-abc123', 'g.ABCDEFGHIJKLMNOP$another']],
		];
		foreach ($cases as $case => [$padId, $listed]) {
			$client = $this->createMock(EtherpadClient::class);
			$client->method('listPads')->with(strstr($padId, '$', true))->willReturn($listed);
			$client->expects($this->never())->method('deleteGroup');
			$client->expects($this->once())->method('deletePad')->with($padId);

			$this->assertTrue($this->lifecycle($client)->discardIfPresent($padId), $case);
		}
	}

	/**
	 * The state every protected delete before this left behind: the pad is
	 * gone, its group is still standing. Nothing else can collect it — a
	 * retry that only deleted the pad again would answer "already gone" and
	 * drop the last row naming the group.
	 */
	public function testDeletesAGroupThatHasBeenEmptied(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abc123';
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('listPads')->with('g.ABCDEFGHIJKLMNOP')->willReturn([]);
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->never())->method('deletePad');

		$this->assertTrue($this->lifecycle($client)->discardIfPresent($padId));
	}

	/**
	 * Reading the group is what makes removing the group safe, not what
	 * makes removing the pad safe, so a read that times out costs the group,
	 * not the delete.
	 */
	public function testStillRemovesThePadWhenTheGroupCannotBeRead(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abc123';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new \RuntimeException('Connection timed out'));
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->once())->method('deletePad')->with($padId);

		$this->assertTrue($this->lifecycle($client)->discardIfPresent($padId));
	}

	/**
	 * A caller that keeps its row and tries again gets the failed read
	 * instead, with nothing removed: the next try may read the group, and
	 * one given up for the pad alone is never looked at again. The caller
	 * reports it, so nothing is logged here.
	 */
	public function testARetriedDiscardRemovesNothingWhenTheGroupCannotBeRead(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new \RuntimeException('Connection timed out'));
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->never())->method('deletePad');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$this->expectExceptionMessage('Connection timed out');

		(new ManagedPadLifecycle($client, $logger))->discardIfPresent('g.ABCDEFGHIJKLMNOP$p-abc123', retried: true);
	}

	public function testDeletesOnlyThePadForAPublicOne(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('listPads');
		$client->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');
		$client->expects($this->never())->method('deleteGroup');

		$this->assertTrue($this->lifecycle($client)->discardIfPresent('nc-abcdef0123456789'));
	}

	/**
	 * A pad Etherpad says does not exist is gone already, and so is one in a
	 * group that does not exist - also when the group goes between reading it
	 * and deleting it. No caller has anything left to do, and none has to
	 * read Etherpad's error for it - not even one that passes on a group it
	 * cannot read (retried).
	 */
	public function testAPadOrGroupGoneAlreadyIsNoError(): void {
		$group = 'g.ABCDEFGHIJKLMNOP';
		$padId = $group . '$p-abc123';
		$cases = [
			'public pad' => ['nc-abcdef0123456789', null, 'padID does not exist', null],
			'group' => [$padId, new \RuntimeException('groupID does not exist'), null, null],
			'group, retried' => [$padId, new \RuntimeException('groupID does not exist'), null, null],
			'group gone after it was read' => [$padId, [$padId], null, 'groupID does not exist'],
			'pad gone from a group that holds others' => [$padId, [$group . '$other'], 'padID does not exist', null],
		];
		foreach ($cases as $case => [$id, $listed, $padAnswer, $groupAnswer]) {
			$client = $this->createMock(EtherpadClient::class);
			if ($listed instanceof \Throwable) {
				$client->method('listPads')->willThrowException($listed);
			} elseif ($listed !== null) {
				$client->method('listPads')->willReturn($listed);
			}
			$deletePad = $client->expects($padAnswer === null ? $this->never() : $this->once())->method('deletePad')->with($id);
			if ($padAnswer !== null) {
				$deletePad->willThrowException(new \RuntimeException($padAnswer));
			}
			$deleteGroup = $client->expects($groupAnswer === null ? $this->never() : $this->once())->method('deleteGroup');
			if ($groupAnswer !== null) {
				$deleteGroup->willThrowException(new \RuntimeException($groupAnswer));
			}

			$this->assertFalse($this->lifecycle($client)->discardIfPresent($id, retried: $case === 'group, retried'), $case);
		}
	}

	/**
	 * Any other failure is the caller's, on every way a pad goes: each caller
	 * does something different about it.
	 */
	public function testAFailedDeleteReachesTheCaller(): void {
		$group = 'g.ABCDEFGHIJKLMNOP';
		$padId = $group . '$p-abc123';
		$cases = [
			'public pad' => ['nc-abcdef0123456789', null, 'deletePad'],
			'the group' => [$padId, [$padId], 'deleteGroup'],
			'the pad in a group that holds others' => [$padId, [$padId, $group . '$other'], 'deletePad'],
		];
		foreach ($cases as $case => [$id, $listed, $failing]) {
			$client = $this->createMock(EtherpadClient::class);
			if ($listed !== null) {
				$client->method('listPads')->willReturn($listed);
			}
			$client->expects($this->once())->method($failing)->willThrowException(new \RuntimeException('Connection timed out'));

			try {
				$this->lifecycle($client)->discardIfPresent($id);
				$this->fail($case . ': the failure did not reach the caller.');
			} catch (\RuntimeException $e) {
				$this->assertSame('Connection timed out', $e->getMessage(), $case);
			}
		}
	}

	/** A sweep's run with no time left makes no call at all, for either kind of pad, and says so. */
	public function testASpentRunMakesNoCall(): void {
		foreach (['g.ABCDEFGHIJKLMNOP$p-abc123', 'nc-abcdef0123456789'] as $padId) {
			$client = $this->createMock(EtherpadClient::class);
			$client->expects($this->never())->method('listPads');
			$client->expects($this->never())->method('deletePad');
			$client->expects($this->never())->method('deleteGroup');

			try {
				$this->lifecycle($client)->discardIfPresent($padId, new RunBudget(new FixedClock(), 1.0));
				$this->fail($padId . ': a call was made without the time to finish it.');
			} catch (RunBudgetSpentException) {
			}
		}
	}

	/**
	 * A caller that has just heard the pad does not exist: a public pad
	 * leaves nothing behind and takes no call, a protected one may have left
	 * its group, which is still looked for and taken when it is empty.
	 */
	public function testAPadKnownAbsentIsOnlyLookedForByItsGroup(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method($this->anything());
		$this->assertFalse($this->lifecycle($client)->discardIfPresent('nc-abcdef0123456789', knownAbsent: true), 'public pad');

		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('listPads')->with('g.ABCDEFGHIJKLMNOP')->willReturn([]);
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$this->assertTrue($this->lifecycle($client)->discardIfPresent('g.ABCDEFGHIJKLMNOP$p-abc123', knownAbsent: true), 'protected pad');
	}

	/**
	 * The loose shape, matching how a binding is classified. A stricter rule
	 * here than in inferAccessModeFromPadId left group pads unrecognised at
	 * delete time, and their groups behind.
	 */
	public function testRecognisesTheSameGroupPadsAsTheAccessModeRuleDoes(): void {
		$padId = 'g.abc123$notes';
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('listPads')->with('g.abc123')->willReturn([$padId]);
		$client->expects($this->once())->method('deleteGroup')->with('g.abc123');

		$this->assertTrue($this->lifecycle($client)->discardIfPresent($padId));
	}

	/**
	 * A create that fails on the way back may still have made the pad. The
	 * id is ours here — unlike the group case there is always something to
	 * clean up with — and if it is not used, nothing will ever name it again.
	 */
	public function testRemovesAPublicPadWhoseCreateFailed(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('createPad')->willThrowException(new \RuntimeException('Connection timed out'));
		$client->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');

		$this->expectException(\RuntimeException::class);
		$this->provisionPublic($client, 'nc-abcdef0123456789');
	}

	/**
	 * The one failure that is not ours to undo: the pad was there before the
	 * call, so deleting it would destroy live content.
	 */
	public function testLeavesAPadThatWasAlreadyThere(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('createPad')->willThrowException(new \RuntimeException('padID does already exist'));
		$client->expects($this->never())->method('deletePad');

		$this->expectException(\RuntimeException::class);
		$this->provisionPublic($client, 'nc-abcdef0123456789');
	}

	/**
	 * A group with no pad in it is invisible to everything afterwards, so the
	 * failure that leaves one has to clean up after itself.
	 */
	public function testRemovesTheGroupWhosePadCouldNotBeCreated(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->once())
			->method('createGroupPad')
			->willThrowException(new \RuntimeException('pad creation failed'));
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');

		$this->expectException(\RuntimeException::class);
		$this->lifecycle($client)->provisionFor(
			BindingService::ACCESS_PROTECTED,
			static fn (): string => 'nc-unused',
			static fn (): string => 'p-abc123',
		);
	}

	/**
	 * The ownership question `discardIfPresent` asks Etherpad is already
	 * answered here, by control flow: the group was made by
	 * `provisionGroupPad` in the same call. Asking again would not make it
	 * safer, only breakable — these are rollbacks, nothing retries them, and
	 * a read that timed out would cost the group and its sessions for good.
	 */
	public function testTakesAProvisionedGroupWithoutAskingWhatIsInIt(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('listPads');
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->never())->method('deletePad');

		$this->lifecycle($client)->discardProvisioned('g.ABCDEFGHIJKLMNOP$p-abc123');
	}

	public function testDiscardsAProvisionedPublicPadAsAPlainPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');

		$this->lifecycle($client)->discardProvisioned('nc-abcdef0123456789');
	}

	public function testProvisionsAPublicPadUnderTheIdItWasGiven(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('createPad')->with('nc-abcdef0123456789');
		$client->expects($this->never())->method('createGroup');

		$padId = $this->lifecycle($client)->provisionFor(
			BindingService::ACCESS_PUBLIC,
			static fn (): string => 'nc-abcdef0123456789',
			static fn (): string => self::fail('the group name was built for a public pad'),
		);

		$this->assertSame('nc-abcdef0123456789', $padId);
	}

	public function testProvisionsAProtectedPadAsAGroupPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('createGroup')->willReturn('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->once())
			->method('createGroupPad')
			->with('g.ABCDEFGHIJKLMNOP', 'p-abc123')
			->willReturn('g.ABCDEFGHIJKLMNOP$p-abc123');
		$client->expects($this->never())->method('createPad');

		$padId = $this->lifecycle($client)->provisionFor(
			BindingService::ACCESS_PROTECTED,
			static fn (): string => self::fail('the public id was built for a protected pad'),
			static fn (): string => 'p-abc123',
		);

		$this->assertSame('g.ABCDEFGHIJKLMNOP$p-abc123', $padId);
	}

	/** Falling through to the public branch would hand out an unprotected pad. */
	public function testRefusesAnAccessModeItDoesNotKnow(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('createPad');
		$client->expects($this->never())->method('createGroup');

		$this->expectException(\InvalidArgumentException::class);
		$this->lifecycle($client)->provisionFor(
			'something-else',
			static fn (): string => 'nc-abcdef0123456789',
			static fn (): string => 'p-abc123',
		);
	}

	public function testSeedsWithHtmlSoFormattingSurvives(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('setHTML')->with('nc-pad', '<p>text</p>');
		$client->expects($this->never())->method('setText');

		$this->lifecycle($client)->seed('nc-pad', 'text', '<p>text</p>');
	}

	public function testSeedsWithPlainTextWhenThereIsNoHtml(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('setHTML');
		$client->expects($this->once())->method('setText')->with('nc-pad', 'text');

		$this->lifecycle($client)->seed('nc-pad', 'text', '   ');
	}

	/** Never both: setText replaces, so it would wipe the HTML just imported. */
	public function testSeedsWithPlainTextOnlyAfterTheHtmlIsRefused(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())
			->method('setHTML')
			->willThrowException(new \RuntimeException('setHTML unsupported'));
		$client->expects($this->once())->method('setText')->with('nc-pad', 'text');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->anything(), $this->callback(
				// The file, not the pad: a pad id plus the configured host is
				// a link into a public pad, and the caller's fileId leads to
				// it through ep_pad_bindings anyway.
				static fn (array $context): bool => ($context['fileId'] ?? 0) === 7
					&& !isset($context['padId'])
			));

		(new ManagedPadLifecycle($client, $logger))->seed('nc-pad', 'text', '<p>text</p>', ['fileId' => 7]);
	}

	/**
	 * Revisions only grow. A pad with fewer than the file's snapshot was
	 * taken at is not the pad the file knew; as many or more is. A file
	 * never synced says nothing, and any pad counts.
	 */
	public function testHoldsAPadToTheRevisionItsSnapshotWasTakenAt(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturn(7);
		$lifecycle = $this->lifecycle($client);

		$this->assertSame(PadPresence::Behind, $lifecycle->presenceOf('nc-pad', 8));
		$this->assertSame(PadPresence::Present, $lifecycle->presenceOf('nc-pad', 7));
		$this->assertSame(PadPresence::Present, $lifecycle->presenceOf('nc-pad', -1));
	}

	/**
	 * A pad behind is left in place by every caller - someone may have
	 * written into it since it came back - so it is logged here, once, with
	 * its id: the last record of where it is. A pad that is the file's is not.
	 */
	public function testLogsAPadBehindWithItsId(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturn(7);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'A pad has fewer revisions than its file\'s snapshot and is no longer the file\'s. It is left in place.',
				['app' => 'etherpad_nextcloud', 'padId' => 'nc-pad', 'fileId' => 7],
			);
		$lifecycle = new ManagedPadLifecycle($client, $logger);

		$this->assertSame(PadPresence::Behind, $lifecycle->probe('nc-pad', 8, ['fileId' => 7])->presence);
		$this->assertSame(PadPresence::Present, $lifecycle->probe('nc-pad', 7, ['fileId' => 7])->presence);
	}

	/**
	 * No caller sees why Etherpad gave no answer, so the cause is logged
	 * here - and its own "does not exist" is an answer, not a failure.
	 */
	public function testLogsWhyEtherpadGaveNoAnswer(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('getRevisionsCount')->willReturnCallback(static function (string $padId, ?int $timeout): int {
			throw new \RuntimeException($padId === 'nc-gone' ? 'padID does not exist' : 'certificate has expired');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->anything(), $this->callback(
				static fn (array $context): bool => ($context['fileId'] ?? 0) === 7
					&& str_contains((string)($context['error_message'] ?? ''), 'certificate has expired')
			));
		$lifecycle = new ManagedPadLifecycle($client, $logger);

		$this->assertSame(PadPresence::Absent, $lifecycle->presenceOf('nc-gone', -1, ['fileId' => 7]));
		$this->assertSame(PadPresence::Unknown, $lifecycle->presenceOf('nc-pad', -1, ['fileId' => 7], 3));
	}
}
