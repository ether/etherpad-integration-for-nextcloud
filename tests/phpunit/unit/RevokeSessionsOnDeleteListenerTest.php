<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RevokeSessionsOnDeleteListener;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
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
		$pads->method('of')->with($node)->willReturn(['g.AAAAAAAAAAAAAAAA$one']);
		$revoked = [];
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->method('revokeForPads')->willReturnCallback(static function (array $padIds) use (&$revoked): int {
			$revoked[] = $padIds;
			return 1;
		});

		$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(LoggerInterface::class));
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
		$pads->method('of')->willReturnCallback(static fn (File $node): array => $node === $locked ? ['g.AAAAAAAAAAAAAAAA$locked'] : []);
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects($this->never())->method('revokeForPads');

		$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(LoggerInterface::class));
		$listener->handle(new BeforeNodeDeletedEvent($locked));
		$listener->handle(new BeforeNodeDeletedEvent($other));
		$listener->handle(new NodeDeletedEvent($other));
	}

	public function testANodeWithoutProtectedPadsAsksEtherpadNothing(): void {
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willReturn([]);
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects($this->never())->method('revokeForPads');

		(new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(LoggerInterface::class)))
			->handle(new BeforeNodeDeletedEvent($this->createMock(File::class)));
	}

	public function testNothingStopsTheDelete(): void {
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->willThrowException(new \RuntimeException('the database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not revoke the Etherpad sessions of pads leaving Files; they will expire on their own.', $this->anything());

		(new RevokeSessionsOnDeleteListener($pads, $this->createMock(PadSessionRevoker::class), $logger))
			->handle(new BeforeNodeDeletedEvent($this->createMock(File::class)));
	}
}
