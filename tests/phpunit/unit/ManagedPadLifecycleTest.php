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

/**
 * A protected pad is a pad inside a group plus the sessions that grant
 * access to it. deletePad removes one of those three, and nothing else in
 * the app ever collected the rest.
 */
class ManagedPadLifecycleTest extends TestCase {
	private function lifecycle(EtherpadClient $client): ManagedPadLifecycle {
		return new ManagedPadLifecycle($client);
	}

	public function testDeletesTheWholeGroupForAProtectedPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('deleteGroup')->with('g.ABCDEFGHIJKLMNOP');
		$client->expects($this->never())->method('deletePad');

		$this->lifecycle($client)->discard('g.ABCDEFGHIJKLMNOP$p-abc123');
	}

	public function testDeletesOnlyThePadForAPublicOne(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('deletePad')->with('nc-abcdef0123456789');
		$client->expects($this->never())->method('deleteGroup');

		$this->lifecycle($client)->discard('nc-abcdef0123456789');
	}

	/**
	 * A legacy Ownpad id is in neither shape. It is deleted the way it always
	 * was rather than refused.
	 */
	public function testTreatsAnUnknownShapeAsAPlainPad(): void {
		$client = $this->createMock(EtherpadClient::class);
		$client->expects($this->once())->method('deletePad')->with('some-legacy-pad');
		$client->expects($this->never())->method('deleteGroup');

		$this->lifecycle($client)->discard('some-legacy-pad');
	}

	public function testReadsTheGroupOutOfAProtectedPadId(): void {
		$lifecycle = $this->lifecycle($this->createMock(EtherpadClient::class));

		$this->assertSame('g.ABCDEFGHIJKLMNOP', $lifecycle->groupIdOf('g.ABCDEFGHIJKLMNOP$p-abc'));
		$this->assertNull($lifecycle->groupIdOf('nc-abcdef0123456789'));
		// Not a group id: too short, and no pad part.
		$this->assertNull($lifecycle->groupIdOf('g.SHORT$p-abc'));
		$this->assertNull($lifecycle->groupIdOf('g.ABCDEFGHIJKLMNOP'));
	}
}
