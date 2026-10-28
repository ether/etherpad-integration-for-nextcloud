<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Service\ClaimedFileWriter;
use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaimedFileWriterTest extends TestCase {
	/**
	 * The document names the pad, carries the snapshot and the pad's URL,
	 * and goes into the file the claim's id resolves to, as the claim left
	 * it; the write is recorded on the claim.
	 */
	public function testWritesThePadsDocumentIntoTheClaimedFile(): void {
		$claim = new CreatedFileClaim('alice', 4242, 'as handed over');
		$snapshot = new PadSnapshot('text', '<p>text</p>', 3);
		$node = $this->fileHolding('as handed over');
		$node->expects($this->once())->method('putContent')->with('the document');
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())->method('buildInitialDocument')
			->with(4242, 'nc-pad', 'public', $snapshot, 'https://pad.example.test/p/nc-pad')
			->willReturn('the document');
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('buildPadUrl')->with('nc-pad')->willReturn('https://pad.example.test/p/nc-pad');

		$padUrl = (new ClaimedFileWriter($this->resolverFor($node), $padFileService, $etherpadClient, $this->createMock(LoggerInterface::class)))
			->writePadDocument($claim, 'nc-pad', 'public', $snapshot);

		$this->assertSame('https://pad.example.test/p/nc-pad', $padUrl);
		$this->assertSame(hash('sha256', 'the document'), $claim->writtenHash);
	}

	/** Content written meanwhile is not written over, and no write is recorded. */
	public function testRefusesAFileWrittenMeanwhile(): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$node = $this->fileHolding('written meanwhile');
		$node->expects($this->never())->method('putContent');

		try {
			$this->writer($node)->write($claim, 'the document');
			$this->fail('the write should have been refused');
		} catch (PadFileChangedException $e) {
			$this->assertSame('The .pad file changed before its document was written.', $e->getMessage());
		}
		$this->assertNull($claim->writtenHash);
	}

	/** A read that gives no string counts as a change: the file is not written over. */
	public function testRefusesAFileThatReadsAsNothing(): void {
		$node = $this->createMock(File::class);
		$node->method('getContent')->willReturn(null);
		$node->expects($this->never())->method('putContent');

		$this->expectException(PadFileChangedException::class);
		$this->writer($node)->write(new CreatedFileClaim('alice', 4242), 'the document');
	}

	/** As handed over, or holding what this create wrote: still the claim's. */
	public function testRecognisesTheFileAsTheClaimLeftIt(): void {
		$written = new CreatedFileClaim('alice', 4242);
		$written->writtenHash = hash('sha256', 'our document');
		$ours = $this->fileHolding('our document');
		$untouched = $this->fileHolding('');

		$this->assertSame($ours, $this->writer($ours)->resolveUnchanged($written));
		$this->assertSame($untouched, $this->writer($untouched)->resolveUnchanged(new CreatedFileClaim('alice', 4242)));
	}

	/** Another's content, a read that gives no string, or a file that cannot be read: not the claim's. */
	public function testDoesNotRecogniseAStrangersContent(): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$claim->writtenHash = hash('sha256', 'our document');
		$unreadable = $this->createMock(File::class);
		$unreadable->method('getContent')->willThrowException(new \RuntimeException('storage gone'));
		$nothing = $this->createMock(File::class);
		$nothing->method('getContent')->willReturn(null);

		$this->assertNull($this->writer($this->fileHolding('somebody else\'s notes'))->resolveUnchanged($claim));
		$this->assertNull($this->writer($unreadable)->resolveUnchanged($claim));
		$this->assertNull($this->writer($nothing)->resolveUnchanged(new CreatedFileClaim('alice', 4242)));
	}

	public function testDeletesTheEmptyFileResolvedFromTheCapturedId(): void {
		$stillOurs = $this->fileHolding('');
		$stillOurs->expects($this->once())->method('delete');

		$this->writer($stillOurs)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242));
	}

	public function testDeletesNothingWhenTheCreatedFileIsNoLongerThere(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new NotFoundException('gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('info')
			->with('Left a claimed .pad file alone: its id did not resolve.', ['app' => 'etherpad_nextcloud', 'uid' => 'alice', 'fileId' => 4242, 'file' => '/Created.pad']);

		$this->writerWith($resolver, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242), ['file' => '/Created.pad']);
	}

	/**
	 * A create that never wrote has no hash to compare, so content in the
	 * file is somebody else's by definition. Deleting it would take work
	 * this create never did.
	 */
	public function testLeavesContentInAFileThisCreateNeverWroteTo(): void {
		$foreign = $this->fileHolding('notes somebody else put here');
		$foreign->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Left a .pad file in place: it is not as this create left it.', ['app' => 'etherpad_nextcloud', 'uid' => 'alice', 'fileId' => 4242, 'file' => '/Created.pad', 'written' => false]);

		$this->writer($foreign, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242), ['file' => '/Created.pad']);
	}

	/** Matching file_id values do not prove that this attempt wrote the document. */
	public function testLeavesADocumentAnotherCreateWroteIntoTheSameFile(): void {
		$rivals = $this->fileHolding("---\nfile_id: 4242\npad_id: nc-rival\n---\n");
		$rivals->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Left a .pad file in place: it is not as this create left it.', ['app' => 'etherpad_nextcloud', 'uid' => 'alice', 'fileId' => 4242, 'written' => true]);

		$this->writer($rivals, $logger)->deleteIfUnchanged($this->claimThatWrote("---\nfile_id: 4242\npad_id: nc-own\n---\n"));
	}

	public function testDeletesAFileHoldingWhatThisCreateWrote(): void {
		$ours = $this->fileHolding('our document');
		$ours->expects($this->once())->method('delete');

		$this->writer($ours)->deleteIfUnchanged($this->claimThatWrote('our document'));
	}

	/** A read failure must not delete the file or mask the original create error. */
	public function testLeavesTheFileAloneWhenItsContentCannotBeRead(): void {
		$locked = $this->createMock(File::class);
		$locked->method('getContent')->willThrowException(new \OCP\Lock\LockedException('/Created.pad'));
		$locked->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not be looked up or read'), $this->anything());

		$this->writer($locked, $logger)->deleteIfUnchanged($this->claimThatWrote('our document'));
	}

	/** Empty, though this create wrote it: not what it wrote, so left, and said so. */
	public function testLeavesAFileEmptiedAfterThisCreateWroteIt(): void {
		$emptied = $this->fileHolding('');
		$emptied->expects($this->never())->method('delete');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('not as this create left it'), $this->anything());

		$this->writer($emptied, $logger)->deleteIfUnchanged($this->claimThatWrote('our document'));
	}

	/** A read that gives no string is a read that failed, not an empty file. */
	public function testLeavesAFileThatReadsAsNothing(): void {
		$unread = $this->createMock(File::class);
		$unread->method('getContent')->willReturn(null);
		$unread->expects($this->never())->method('delete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not be looked up or read'), $this->anything());

		$this->writer($unread, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242));
	}

	public function testSwallowsAFailedLookup(): void {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->willThrowException(new \RuntimeException('storage down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Left a .pad file in place: it could not be looked up or read.',
			$this->callback(static fn (array $context): bool => array_diff_key($context, ['error_origin' => true]) === [
				'app' => 'etherpad_nextcloud',
				'uid' => 'alice',
				'fileId' => 4242,
				'file' => '/Created.pad',
				'error' => \RuntimeException::class,
				'error_message' => 'storage down',
			]),
		);

		$this->writerWith($resolver, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242), ['file' => '/Created.pad']);
	}

	/**
	 * A cleanup failure must not replace the error that caused the
	 * rollback. It is named with the claimed file's id, not with whatever
	 * the node found by its id answers by then.
	 */
	public function testReportsButSwallowsADeleteFailure(): void {
		$resolved = $this->fileHolding('');
		$resolved->method('getId')->willReturn(42);
		$resolved->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Could not remove the .pad file of a failed create.',
			$this->callback(static fn (array $context): bool => array_diff_key($context, ['error_origin' => true]) === [
				'app' => 'etherpad_nextcloud',
				'uid' => 'alice',
				'fileId' => 4242,
				'file' => '/Created.pad',
				'error' => \RuntimeException::class,
				'error_message' => 'nope',
			]),
		);

		$this->writer($resolved, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242), ['file' => '/Created.pad']);
	}

	private function claimThatWrote(string $content): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', 4242);
		$claim->writtenHash = hash('sha256', $content);

		return $claim;
	}

	private function writer(File $node, ?LoggerInterface $logger = null): ClaimedFileWriter {
		return $this->writerWith($this->resolverFor($node), $logger);
	}

	private function writerWith(UserNodeResolver $resolver, ?LoggerInterface $logger = null): ClaimedFileWriter {
		return new ClaimedFileWriter($resolver, $this->createMock(PadFileService::class), $this->createMock(EtherpadClient::class), $logger ?? $this->createMock(LoggerInterface::class));
	}

	/** @return File&\PHPUnit\Framework\MockObject\MockObject */
	private function fileHolding(string $content): File {
		$node = $this->createMock(File::class);
		$node->method('getContent')->willReturn($content);
		return $node;
	}

	private function resolverFor(File $node): UserNodeResolver {
		$resolver = $this->createMock(UserNodeResolver::class);
		$resolver->method('resolveUserFileNodeById')->with('alice', 4242)->willReturn($node);
		return $resolver;
	}
}
