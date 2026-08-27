<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateRollbackServiceTest extends TestCase {
	public function testRollbackTouchesNothingWhenNoFileWasCreated(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('findOwnedUserFileById');

		$this->buildService(resolver: $resolver)
			->rollbackFailedCreate('alice', '/Existing.pad', '', 0);
	}

	public function testRollbackDeletesTheFileItCreated(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($created))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	/**
	 * Cleanup follows the id, so a file renamed or moved while Etherpad was
	 * being provisioned is still the one deleted — and whatever took its old
	 * name is not.
	 */
	public function testRollbackFollowsTheFileRatherThanTheName(): void {
		$movedAway = $this->createMock(File::class);
		$movedAway->method('getPath')->willReturn('/alice/files/Archive/Created.pad');
		$movedAway->expects($this->once())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($movedAway))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	/**
	 * Nothing safe to delete — but the leftover has to be traceable to the
	 * create that abandoned it, or it is just an unexplained file.
	 */
	public function testRollbackWarnsWhenTheFileCannotBeIdentified(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not identify'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad' && $c['fileId'] === 4711),
			);

		$this->buildService(resolver: $this->resolverReturning(null), logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	public function testRollbackDeletesTheProvisionedPad(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('g.ABC$pad');

		$this->buildService(resolver: $this->resolverNotConsulted(), etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 0);
	}

	public function testRollbackLeavesEtherpadAloneWithoutAPadId(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(resolver: $this->resolverNotConsulted(), etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', '', 0);
	}

	/** A cleanup failure must not replace the error that caused the rollback. */
	public function testRollbackReportsButSwallowsADeleteFailure(): void {
		$created = $this->createMock(File::class);
		$created->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not cleanup failed .pad file create'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad'),
			);

		$this->buildService(resolver: $this->resolverReturning($created), logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	public function testRollbackReportsButSwallowsAPadDeleteFailure(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('etherpad down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not cleanup failed Etherpad create'), $this->anything());

		$this->buildService(resolver: $this->resolverNotConsulted(), etherpad: $etherpad, logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 0);
	}

	/** An external create links a remote pad, so there is none of ours to delete. */
	public function testExternalRollbackDeletesTheFileButNoPad(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(resolver: $this->resolverReturning($created), etherpad: $etherpad)
			->rollbackExternalCreate('alice', '/Created.pad', 4711);
	}

	/** Expects exactly one lookup, which is what every file-carrying case does. */
	private function resolverReturning(?File $node): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('findOwnedUserFileById')
			->with('alice', 4711)
			->willReturn($node);
		return $resolver;
	}

	/** For the pad-only cases, where no file was created to look up. */
	private function resolverNotConsulted(): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('findOwnedUserFileById');
		return $resolver;
	}

	private function buildService(
		?UserNodeResolver $resolver = null,
		?EtherpadClient $etherpad = null,
		?LoggerInterface $logger = null,
	): PadCreateRollbackService {
		return new PadCreateRollbackService(
			$resolver ?? $this->createMock(UserNodeResolver::class),
			$etherpad ?? $this->createMock(EtherpadClient::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
