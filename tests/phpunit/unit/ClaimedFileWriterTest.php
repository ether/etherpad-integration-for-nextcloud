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
use PHPUnit\Framework\TestCase;

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

		$padUrl = (new ClaimedFileWriter($this->resolverFor($node), $padFileService, $etherpadClient))
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

	private function writer(File $node): ClaimedFileWriter {
		return new ClaimedFileWriter($this->resolverFor($node), $this->createMock(PadFileService::class), $this->createMock(EtherpadClient::class));
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
