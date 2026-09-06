<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
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

		$this->lifecycle($client)->discard($padId);
	}

	/**
	 * The one that matters: a binding's pad id need not name a group this app
	 * created. A legacy Ownpad `.pad` names its own pad id and the migration
	 * binds it as given, so a hand-written file naming someone else's group
	 * would otherwise have made deleting that file destroy their group, their
	 * pad and their sessions.
	 */
	public function testLeavesAGroupAloneWhenItHoldsSomethingElse(): void {
		$padId = 'g.VICTIMGROUPID12$made-up';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->with('g.VICTIMGROUPID12')->willReturn(['g.VICTIMGROUPID12$their-real-pad']);
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->once())->method('deletePad')->with($padId);

		$this->lifecycle($client)->discard($padId);
	}

	public function testLeavesAGroupAloneWhenItHoldsMoreThanThisPad(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abc123';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willReturn([$padId, 'g.ABCDEFGHIJKLMNOP$another']);
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->once())->method('deletePad')->with($padId);

		$this->lifecycle($client)->discard($padId);
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

		$this->lifecycle($client)->discard($padId);
	}

	/**
	 * Reading the group is what makes removing the group safe, not what
	 * makes removing the pad safe. Most callers are rollbacks with no retry
	 * behind them, so a read that times out must cost the group, not the
	 * delete.
	 */
	public function testStillRemovesThePadWhenTheGroupCannotBeRead(): void {
		$padId = 'g.ABCDEFGHIJKLMNOP$p-abc123';
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new \RuntimeException('Connection timed out'));
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->once())->method('deletePad')->with($padId);

		$this->lifecycle($client)->discard($padId);
	}

	public function testDeletesOnlyThePadForAPublicOne(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->never())->method('listPads');
		$client->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');
		$client->expects($this->never())->method('deleteGroup');

		$this->lifecycle($client)->discard('nc-abcdef0123456789');
	}

	/**
	 * A group that is not there means the pad inside it is not there either,
	 * so the error travels to the caller, whose classifier reads it as
	 * "already gone".
	 */
	public function testLetsAMissingGroupReachTheCaller(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->method('listPads')->willThrowException(new \RuntimeException('groupID does not exist'));
		$client->expects($this->never())->method('deleteGroup');
		$client->expects($this->never())->method('deletePad');

		$this->expectExceptionMessage('groupID does not exist');
		$this->lifecycle($client)->discard('g.ABCDEFGHIJKLMNOP$p-abc123');
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

		$this->lifecycle($client)->discard($padId);
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
	 * The ownership question `discard` asks Etherpad is already answered
	 * here, by control flow: the group was made by `provisionGroupPad` in
	 * the same call. Asking again would not make it safer, only breakable —
	 * these are rollbacks, nothing retries them, and a read that timed out
	 * would cost the group and its sessions for good.
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
				static fn (array $context): bool => ($context['padId'] ?? '') === 'nc-pad' && ($context['fileId'] ?? 0) === 7
			));

		(new ManagedPadLifecycle($client, $logger))->seed('nc-pad', 'text', '<p>text</p>', ['fileId' => 7]);
	}
}
