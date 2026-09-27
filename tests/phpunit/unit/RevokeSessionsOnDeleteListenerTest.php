<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\RevokeSessionsOnDeleteListener;
use OCA\EtherpadNextcloud\Service\PadSessionRevoker;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A delete, to the trash or past it, takes the sessions of the protected
 * pads it takes along; a node without any asks Etherpad nothing, and
 * nothing here stops the delete.
 */
class RevokeSessionsOnDeleteListenerTest extends TestCase {
	public function testADeleteTakesTheSessionsOfItsProtectedPads(): void {
		$node = $this->createMock(File::class);
		$pads = $this->createMock(ProtectedPadsOfNode::class);
		$pads->method('of')->with($node)->willReturn(['g.AAAAAAAAAAAAAAAA$one']);
		$revoker = $this->createMock(PadSessionRevoker::class);
		$revoker->expects($this->once())->method('revokeForPads')->with(['g.AAAAAAAAAAAAAAAA$one']);

		$listener = new RevokeSessionsOnDeleteListener($pads, $revoker, $this->createMock(LoggerInterface::class));
		$listener->handle(new BeforeNodeDeletedEvent($node));
		$listener->handle(new Event());
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
