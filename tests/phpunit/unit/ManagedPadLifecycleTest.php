<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

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
}
