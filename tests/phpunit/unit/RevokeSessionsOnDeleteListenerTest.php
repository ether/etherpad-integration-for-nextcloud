<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RevokeSessionsOnDeleteListener;
use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\ProtectedPadsFound;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A delete, to the trash or past it, takes the sessions of the protected
 * pads it takes along once it is done; one that fails takes none, a node
 * without any asks Etherpad nothing, and nothing here stops the delete.
 */
class RevokeSessionsOnDeleteListenerTest extends TestCase {
	public function testADeleteTakesTheSessionsOfItsProtectedPadsOnceDone(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(7);
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->with($node)->willReturn(new ProtectedPadsFound(['g.AAAAAAAAAAAAAAAA$one']));
		$revoked = [];
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->method('revokeForPads')->willReturnCallback(static function (array $padIds) use (&$revoked): int {
			$revoked[] = $padIds;
			return 1;
		});

		$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(FolderSessionRevoker::class), $this->createMock(LoggerInterface::class));
		$listener->handle(new BeforeNodeDeletedEvent($node));
		$this->assertSame([], $revoked, 'nothing before the delete is done');
		$listener->handle(new Event());
		$listener->handle(new NodeDeletedEvent($node));

		$this->assertSame([['g.AAAAAAAAAAAAAAAA$one']], $revoked);
	}

	/** A delete that fails - a file locked by a sync - raises no NodeDeletedEvent, and takes no sessions. */
	public function testAFailedDeleteTakesNoSessions(): void {
		$locked = $this->createMock(File::class);
		$locked->method('getId')->willReturn(7);
		$other = $this->createMock(File::class);
		$other->method('getId')->willReturn(8);
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willReturnCallback(static fn (File $node): ProtectedPadsFound => new ProtectedPadsFound($node === $locked ? ['g.AAAAAAAAAAAAAAAA$locked'] : []));
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects($this->never())->method('revokeForPads');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(FolderSessionRevoker::class), $logger);
		$listener->handle(new BeforeNodeDeletedEvent($locked));
		$listener->handle(new BeforeNodeDeletedEvent($other));
		$listener->handle(new NodeDeletedEvent($other));
	}

	/**
	 * Two BeforeNodeDeletedEvents with no NodeDeletedEvent between them
	 * stand for a delete that failed and was tried again: what the first
	 * found is not revoked, whatever the second finds or whether it throws.
	 */
	public function testADeleteTriedAgainTakesOnlyWhatItFindsThen(): void {
		foreach ([
			'none found' => [[], []],
			'another pad found' => [['g.BBBBBBBBBBBBBBBB$two'], [['g.BBBBBBBBBBBBBBBB$two']]],
			'finding them failed' => [new \RuntimeException('lock wait timeout'), []],
		] as $case => [$second, $expected]) {
			$node = $this->createMock(File::class);
			$node->method('getId')->willReturn(7);
			$found = [['g.AAAAAAAAAAAAAAAA$one'], $second];
			$pads = $this->createMock(ProtectedPadsOfNode::class);
			$pads->method('of')->willReturnCallback(static function () use (&$found): ProtectedPadsFound {
				$next = array_shift($found);
				if ($next instanceof \Throwable) {
					throw $next;
				}
				return new ProtectedPadsFound($next ?? []);
			});
			$revoked = [];
			$revoker = $this->createMock(PadSessionRevoker::class);
			$revoker->method('revokeForPads')->willReturnCallback(static function (array $padIds) use (&$revoked): int {
				$revoked[] = $padIds;
				return 1;
			});

			$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(FolderSessionRevoker::class), $this->createMock(LoggerInterface::class));
			$listener->handle(new BeforeNodeDeletedEvent($node));
			$listener->handle(new BeforeNodeDeletedEvent($node));
			$listener->handle(new NodeDeletedEvent($node));

			$this->assertSame($expected, $revoked, $case);
		}
	}

	public function testANodeWithoutProtectedPadsAsksEtherpadNothing(): void {
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willReturn(new ProtectedPadsFound([]));
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects($this->never())->method('revokeForPads');

		(new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(FolderSessionRevoker::class), $this->createMock(LoggerInterface::class)))
			->handle(new BeforeNodeDeletedEvent($this->createMock(File::class)));
	}

	/**
	 * A walk stopped short hands the folder to a job once the delete is done,
	 * with no pad found as well; a delete that fails hands over nothing.
	 */
	public function testAWalkStoppedShortGoesToAJobOnceTheDeleteIsDone(): void {
		foreach ([
			'with pads found' => [['g.AAAAAAAAAAAAAAAA$one'], 1],
			'with none found' => [[], 0],
		] as $case => [$found, $revokes]) {
			$node = $this->createMock(Folder::class);
			$node->method('getId')->willReturn(7);
			$pads = $this->createMock(ProtectedPadsOfNode::class);
			$pads->method('of')->willReturn(new ProtectedPadsFound($found, true));
			$revoker = $this->createMock(PadSessionRevoker::class);
			$revoker->expects($this->exactly($revokes))->method('revokeForPads')->with($found);
			$queued = [];
			$folders = $this->createMock(FolderSessionRevoker::class);
			$folders->method('queue')->willReturnCallback(static function (int $fileId) use (&$queued): void {
				$queued[] = $fileId;
			});
			$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $folders, $this->createMock(LoggerInterface::class));

			$listener->handle(new BeforeNodeDeletedEvent($node));
			$this->assertSame([], $queued, $case . ': nothing before the delete is done');
			$listener->handle(new NodeDeletedEvent($node));
			$this->assertSame([7], $queued, $case);
		}
	}

	/** A walk that reached the end leaves the job out. */
	public function testAWalkThatReachedTheEndQueuesNoJob(): void {
		$node = $this->createMock(Folder::class);
		$node->method('getId')->willReturn(7);
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willReturn(new ProtectedPadsFound(['g.AAAAAAAAAAAAAAAA$one']));
		$folders = $this->createMock(FolderSessionRevoker::class);
		$folders->expects($this->never())->method('queue');
		$listener = new RevokeSessionsOnDeleteListener($pads, $this->createMock(PadSessionRevoker::class), $folders, $this->createMock(LoggerInterface::class));

		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle(new NodeDeletedEvent($node));
	}

	/** A delete that fails raises no NodeDeletedEvent, and what it found goes with its next try. */
	public function testAFailedDeleteQueuesNoJob(): void {
		$node = $this->createMock(Folder::class);
		$node->method('getId')->willReturn(7);
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willReturn(new ProtectedPadsFound([], true));
		$folders = $this->createMock(FolderSessionRevoker::class);
		$folders->expects($this->never())->method('queue');
		$listener = new RevokeSessionsOnDeleteListener($pads, $this->createMock(PadSessionRevoker::class), $folders, $this->createMock(LoggerInterface::class));

		$listener->handle(new BeforeNodeDeletedEvent($node));
	}

	public function testNothingStopsTheDelete(): void {
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willThrowException(new \RuntimeException('the database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not revoke the Etherpad sessions of pads leaving Files; they will expire on their own.', $this->anything());

		(new RevokeSessionsOnDeleteListener($pads, $this->createMock(PadSessionRevoker::class), $this->createMock(FolderSessionRevoker::class), $logger))
			->handle(new BeforeNodeDeletedEvent($this->createMock(File::class)));
	}
}
