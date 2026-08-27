<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateRollbackServiceTest extends TestCase {
	public function testRollbackTouchesNothingWhenNoFileWasCreated(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$this->buildService($rootFolder)
			->rollbackFailedCreate('alice', '/Existing.pad', '', 0);
	}

	public function testRollbackDeletesTheFileItCreated(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$this->buildService($this->rootFolderReturning($created, 4711))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	/**
	 * The path is only carried for log lines. Cleanup follows the id, so a
	 * file that was renamed or moved while Etherpad was being provisioned is
	 * still the one that gets deleted — and whatever took its old name is
	 * not.
	 */
	public function testRollbackFollowsTheFileRatherThanTheName(): void {
		$movedAway = $this->createMock(File::class);
		$movedAway->method('getPath')->willReturn('/alice/files/Archive/Created.pad');
		$movedAway->expects($this->once())->method('delete');

		$this->buildService($this->rootFolderReturning($movedAway, 4711))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	public function testRollbackDeletesNothingWhenTheFileIsAlreadyGone(): void {
		$this->buildService($this->rootFolderReturning(null, 4711))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);

		$this->addToAssertionCount(1);
	}

	/** A folder sharing the id is not what this create made. */
	public function testRollbackIgnoresANodeThatIsNotAFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('delete');

		$this->buildService($this->rootFolderReturning($folder, 4711))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);
	}

	public function testExternalRollbackDeletesTheFileItCreated(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$this->buildService($this->rootFolderReturning($created, 4711))
			->rollbackExternalCreate('alice', '/Created.pad', 4711);
	}

	/** A cleanup failure must not replace the error that caused the rollback. */
	public function testRollbackSwallowsADeleteFailure(): void {
		$created = $this->createMock(File::class);
		$created->method('delete')->willThrowException(new \RuntimeException('nope'));

		$this->buildService($this->rootFolderReturning($created, 4711))
			->rollbackFailedCreate('alice', '/Created.pad', '', 4711);

		$this->addToAssertionCount(1);
	}

	private function rootFolderReturning(mixed $node, int $fileId): IRootFolder {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('getFirstNodeById')
			->with($fileId)
			->willReturn($node);
		// Nothing may be resolved by name any more.
		$userFolder->expects($this->never())->method('get');
		$userFolder->expects($this->never())->method('nodeExists');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);
		return $rootFolder;
	}

	private function buildService(IRootFolder $rootFolder): PadCreateRollbackService {
		return new PadCreateRollbackService(
			$rootFolder,
			$this->createMock(EtherpadClient::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
