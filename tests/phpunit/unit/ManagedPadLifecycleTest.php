<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PadPresence;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
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
	/**
	 * Lost is gone altogether - a protected pad, or a public one whose file
	 * holds saved content - or made anew without a revision while the file
	 * holds saved content. Saved content is a snapshot past the first
	 * revision, or any text: a file made from a template by 1.1.0-beta.1
	 * holds its content at revision 0. A public pad with nothing saved,
	 * Etherpad makes on a visit; behind but written into is not lost, nor is
	 * an untouched pad whose file holds nothing, nor a pad at revision 0
	 * holding the text the file saved - its history cut short in Etherpad.
	 * A pad at revision 0 with other text - Etherpad's default, from a
	 * visit - is made anew. Anything else Etherpad answers, or its silence,
	 * is the caller's. Each question within a few seconds.
	 */
	public function testHowAPadIsLost(): void {
		$gone = new EtherpadRefusedException('padID does not exist');
		// Etherpad's answer, access mode, snapshot_rev, verdict, the pad's
		// text, the file's saved text.
		$cases = [
			'protected, gone' => [$gone, BindingService::ACCESS_PROTECTED, -1, PadPresence::Absent, '', ''],
			'public, gone, with saved content' => [$gone, BindingService::ACCESS_PUBLIC, 5, PadPresence::Absent, '', 'Saved text'],
			'public, gone, nothing saved' => [$gone, BindingService::ACCESS_PUBLIC, 0, null, '', ''],
			'public, gone, only a newline saved' => [$gone, BindingService::ACCESS_PUBLIC, 0, null, '', "\n"],
			'public, gone, snapshot unknown' => [$gone, BindingService::ACCESS_PUBLIC, -1, null, '', ''],
			'public, gone, a template\'s text at revision 0' => [$gone, BindingService::ACCESS_PUBLIC, 0, PadPresence::Absent, '', 'Saved text'],
			'made anew on a visit, with the default text' => [0, BindingService::ACCESS_PUBLIC, 5, PadPresence::Behind, "Welcome to Etherpad!\n", 'Saved text'],
			'made anew on a visit, a template\'s text at revision 0' => [0, BindingService::ACCESS_PUBLIC, 0, PadPresence::Behind, "Welcome to Etherpad!\n", 'Saved text'],
			'made anew through the API, empty' => [0, BindingService::ACCESS_PUBLIC, 5, PadPresence::Behind, "\n", 'Saved text'],
			'behind, written into' => [3, BindingService::ACCESS_PUBLIC, 5, null, '', 'Saved text'],
			'history cut short, text as saved' => [0, BindingService::ACCESS_PUBLIC, 5, null, "Saved text\r\n\n", 'Saved text'],
			'a template\'s pad untouched at revision 0' => [0, BindingService::ACCESS_PUBLIC, 0, null, "Saved text\n", 'Saved text'],
			'there' => [7, BindingService::ACCESS_PROTECTED, 5, null, '', 'Saved text'],
			'untouched, no snapshot' => [0, BindingService::ACCESS_PUBLIC, 0, null, "Welcome to Etherpad!\n", ''],
			'protected, new file, its pad untouched' => [0, BindingService::ACCESS_PROTECTED, -1, null, "Welcome to Etherpad!\n", ''],
		];
		foreach ($cases as $case => [$answer, $accessMode, $snapshot, $lost, $padText, $savedText]) {
			$client = $this->createMock(EtherpadClient::class);
			$call = $client->method('getRevisionsCount')->with('pad-1', ManagedPadLifecycle::PROBE_TIMEOUT_SECONDS);
			$answer instanceof \Throwable ? $call->willThrowException($answer) : $call->willReturn($answer);
			$client->method('getText')->with('pad-1', ManagedPadLifecycle::PROBE_TIMEOUT_SECONDS)->willReturn($padText);

			$this->assertSame($lost, $this->lifecycle($client)->howLost('pad-1', $accessMode, self::fileSaved($snapshot, $savedText)), $case);
		}

		foreach ([new EtherpadClientException('Etherpad API request failed'), new EtherpadRefusedException('apikey is invalid')] as $error) {
			$client = $this->createMock(EtherpadClient::class);
			$client->method('getRevisionsCount')->willThrowException($error);
			try {
				$this->lifecycle($client)->howLost('pad-1', BindingService::ACCESS_PROTECTED, self::fileSaved(5, 'Saved text'));
				$this->fail('no answer taken for an answer: ' . $error->getMessage());
			} catch (EtherpadClientException $e) {
				$this->assertSame($error, $e);
			}
		}
	}

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

		$this->lifecycle($client)->discardIfPresent($padId);
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

			$this->lifecycle($client)->discardIfPresent($padId);
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

		$this->lifecycle($client)->discardIfPresent($padId);
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

		$this->lifecycle($client)->discardIfPresent($padId);
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

		$this->lifecycle($client)->discardIfPresent('nc-abcdef0123456789');
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

			$this->lifecycle($client)->discardIfPresent($id, retried: $case === 'group, retried');
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
		$this->lifecycle($client)->discardIfPresent('nc-abcdef0123456789', knownAbsent: true);

		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('listPads')->with('g.ABCDEFGHIJKLMNOP')->willReturn([]);
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$this->lifecycle($client)->discardIfPresent('g.ABCDEFGHIJKLMNOP$p-abc123', knownAbsent: true);
	}

	/**
	 * Only an empty group goes after a pad known absent. The pad may be back
	 * by then - made anew through the API - and is not the call's to take;
	 * a group that cannot be read stays, and the caller hears why.
	 */
	public function testAPadKnownAbsentTakesNoGroupThatIsNotEmpty(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn(['g.ABCDEFGHIJKLMNOP$p-abc123']);
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->never())->method('deletePad');
		$this->lifecycle($client)->discardIfPresent('g.ABCDEFGHIJKLMNOP$p-abc123', knownAbsent: true);

		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new EtherpadClientException('Connection timed out'));
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->never())->method('deletePad');
		$this->expectException(EtherpadClientException::class);
		$this->lifecycle($client)->discardIfPresent('g.ABCDEFGHIJKLMNOP$p-abc123', knownAbsent: true);
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

		$this->lifecycle($client)->discardIfPresent($padId);
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

	/** A file as readPad() gives it, of which only what it saved matters here. */
	private static function fileSaved(int $snapshotRev, string $savedText): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: 'pad-1',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: $snapshotRev,
			savedText: $savedText,
		);
	}

	/** A public pad with nothing saved in its file is never lost: Etherpad is not asked about it. */
	public function testAPublicPadWithNothingSavedIsNotAskedAbout(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('getRevisionsCount');

		foreach ([-1, 0] as $snapshot) {
			foreach (['', "\n", " \r\n"] as $savedText) {
				$this->assertNull($this->lifecycle($client)->howLost('pad-1', BindingService::ACCESS_PUBLIC, self::fileSaved($snapshot, $savedText)));
			}
		}
	}

	/**
	 * An open stops only on a definite answer. Etherpad slow, silent or
	 * refusing the question opens the pad as before, with a line at debug.
	 */
	public function testAnOpenStopsOnlyForAPadKnownLost(): void {
		$gone = $this->createMock(EtherpadClient::class);
		$gone->method('getRevisionsCount')->willThrowException(new EtherpadRefusedException('padID does not exist'));
		$this->assertTrue($this->lifecycle($gone)->isKnownLost('pad-1', BindingService::ACCESS_PROTECTED, self::fileSaved(5, 'Saved text')));

		foreach ([new EtherpadClientException('Etherpad API request failed'), new EtherpadRefusedException('apikey is invalid')] as $error) {
			$client = $this->createMock(EtherpadClient::class);
			$client->method('getRevisionsCount')->willThrowException($error);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('debug')->with('Could not ask Etherpad whether a pad is lost; opened as before.', $this->anything());
			$this->assertFalse((new ManagedPadLifecycle($client, $logger))->isKnownLost('pad-1', BindingService::ACCESS_PROTECTED, self::fileSaved(5, 'Saved text')), $error->getMessage());
		}

		// A fault of the check itself opens the pad too, but says so aloud.
		$broken = $this->createMock(EtherpadClient::class);
		$broken->method('getRevisionsCount')->willThrowException(new \TypeError('a call gone wrong'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$logger->expects($this->once())->method('warning')->with('Could not tell whether a pad is lost; opened as before.', $this->anything());
		$this->assertFalse((new ManagedPadLifecycle($broken, $logger))->isKnownLost('pad-1', BindingService::ACCESS_PROTECTED, self::fileSaved(5, 'Saved text')));
	}

	/** Seeding says how many revisions the pad has then: what its file records as synced. */
	public function testSeedsWithHtmlSoFormattingSurvives(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('setHTML')->with('nc-pad', '<p>text</p>');
		$client->expects($this->never())->method('setText');
		$client->method('getRevisionsCount')->with('nc-pad')->willReturn(2);

		$this->assertSame(2, $this->lifecycle($client)->seed('nc-pad', 'text', '<p>text</p>'));
	}

	/** Etherpad not saying how many revisions leaves the file to its first sync. */
	public function testSeedsWithPlainTextWhenThereIsNoHtml(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('setHTML');
		$client->expects($this->once())->method('setText')->with('nc-pad', 'text');
		$client->method('getRevisionsCount')->willThrowException(new EtherpadClientException('Etherpad API request failed'));

		$this->assertSame(-1, $this->lifecycle($client)->seed('nc-pad', 'text', '   '));
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
}
