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
		$resolver->expects($this->never())->method('findUserFileById');

		$this->buildService(resolver: $resolver)
			->rollbackFailedCreate('alice', '/Existing.pad', '', 0);
	}

	public function testRollbackDeletesTheFileItWrote(): void {
		$created = $this->fileContaining('g.ABC$pad');
		$created->expects($this->once())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($created))
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
	}

	/**
	 * An empty file is not proof. newFile() hands back a file that already
	 * existed, so a create that lost the race by microseconds can be holding
	 * an empty file somebody else just made — and nothing afterwards tells
	 * the two apart. The cost is a leftover the user can delete; the
	 * alternative is deleting a file they made.
	 */
	public function testRollbackLeavesAnEmptyFileAlone(): void {
		$empty = $this->emptyFile();
		$empty->expects($this->never())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($empty))
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
	}

	/**
	 * Cleanup follows the id, so a file renamed or moved while Etherpad was
	 * being provisioned is still the one deleted — and whatever took its old
	 * name is not.
	 */
	public function testRollbackFollowsTheFileRatherThanTheName(): void {
		$movedAway = $this->fileContaining('g.ABC$pad');
		$movedAway->method('getPath')->willReturn('/alice/files/Archive/Created.pad');
		$movedAway->expects($this->once())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($movedAway))
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
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
		$created = $this->fileContaining('g.ABC$pad');
		$created->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not cleanup failed .pad file create'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad'),
			);

		$this->buildService(resolver: $this->resolverReturning($created), logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
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
	/**
	 * An external create links a remote pad, so there is none of ours to
	 * delete — and no pad id, so the file cannot be attributed either and is
	 * left where it is.
	 */
	public function testExternalRollbackDeletesNoPadAndKeepsTheFile(): void {
		$created = $this->fileContaining('anything');
		$created->expects($this->never())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(resolver: $this->resolverReturning($created), etherpad: $etherpad)
			->rollbackExternalCreate('alice', '/Created.pad', 4711);
	}

	/**
	 * Nextcloud has no create-if-absent, so a create that lost a race by
	 * microseconds can be holding a file somebody else made. Its id would
	 * then be the id this rollback deletes by — unless the contents say
	 * otherwise.
	 */
	public function testRollbackLeavesAFileHoldingSomeoneElsesContent(): void {
		$stranger = $this->createMock(File::class);
		$stranger->method('getSize')->willReturn(120);
		$stranger->method('getContent')->willReturn("Bob's notes, nothing to do with us");
		$stranger->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())
			->method('warning')
			->with($this->stringContains('Could not confirm'), $this->anything());

		$this->buildService(resolver: $this->resolverReturning($stranger), logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
	}

	/** Our own half-written document names the pad this create provisioned. */
	public function testRollbackDeletesAFileCarryingOurPadId(): void {
		$ours = $this->createMock(File::class);
		$ours->method('getSize')->willReturn(200);
		$ours->method('getContent')->willReturn("---\npad_id: \"g.ABC\$pad\"\n---\n");
		$ours->expects($this->once())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($ours))
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
	}

	public function testRollbackLeavesAFileItCannotRead(): void {
		$unreadable = $this->createMock(File::class);
		$unreadable->method('getSize')->willReturn(10);
		$unreadable->method('getContent')->willThrowException(new \RuntimeException('locked'));
		$unreadable->expects($this->never())->method('delete');

		$this->buildService(resolver: $this->resolverReturning($unreadable))
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', 4711);
	}

	private function fileContaining(string $needle): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(200);
		$file->method('getContent')->willReturn("---\npad_id: \"$needle\"\n---\n");
		return $file;
	}

	private function emptyFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(0);
		return $file;
	}

	/** Expects exactly one lookup, which is what every file-carrying case does. */
	private function resolverReturning(?File $node): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->once())
			->method('findUserFileById')
			->with('alice', 4711)
			->willReturn($node);
		return $resolver;
	}

	/** For the pad-only cases, where no file was created to look up. */
	private function resolverNotConsulted(): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->expects($this->never())->method('findUserFileById');
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
