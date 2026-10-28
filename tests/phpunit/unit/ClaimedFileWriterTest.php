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
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaimedFileWriterTest extends TestCase {
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

	/** @return iterable<string,array{?string}> what the claimed id finds where the claim expects nothing */
	public static function filesTheWriteRefuses(): iterable {
		yield 'written meanwhile' => ['written meanwhile'];
		// A read that gives no string counts as a change.
		yield 'a read that gives no string' => [null];
	}

	/** Content written meanwhile is not written over, and no write is recorded. */
	#[DataProvider('filesTheWriteRefuses')]
	public function testRefusesAFileThatIsNotAsTheClaimExpects(?string $content): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$node = $this->createMock(File::class);
		$node->method('getContent')->willReturn($content);
		$node->expects($this->never())->method('putContent');

		try {
			$this->writer($node)->write($claim, 'the document');
			$this->fail('the write should have been refused');
		} catch (PadFileChangedException $e) {
			$this->assertSame('The .pad file changed before its document was written.', $e->getMessage());
		}
		$this->assertNull($claim->writtenHash);
	}

	/**
	 * What the claimed id finds, what this create wrote (null: nothing), and
	 * whether the file goes. A file that stays is named, with whether this
	 * create wrote it.
	 *
	 * @return iterable<string,array{string,?string,bool}>
	 */
	public static function filesTheRollbackFinds(): iterable {
		yield 'as handed over' => ['', null, true];
		yield 'what it wrote' => ['our document', 'our document', true];
		yield 'content it never wrote' => ['notes somebody else put here', null, false];
		yield 'emptied after its write' => ['', 'our document', false];
	}

	#[DataProvider('filesTheRollbackFinds')]
	public function testRemovesTheClaimedFileOnlyWhileItIsAsTheClaimLeftIt(string $content, ?string $wrote, bool $goes): void {
		$claim = new CreatedFileClaim('alice', 4242);
		$claim->writtenHash = $wrote === null ? null : hash('sha256', $wrote);
		$found = $this->fileHolding($content);
		$found->expects($goes ? $this->once() : $this->never())->method('delete');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($goes ? $this->never() : $this->once())->method('warning')->with(
			'Left a .pad file in place: it is not as this create left it.',
			['app' => 'etherpad_nextcloud', 'uid' => 'alice', 'fileId' => 4242, 'file' => '/Created.pad', 'written' => $wrote !== null],
		);

		$this->writer($found, $logger)->deleteIfUnchanged($claim, ['file' => '/Created.pad']);
	}

	/**
	 * How the lookup by the claimed id fails (null: it finds the file), how
	 * reading that file fails (null: it gives no string), and the line that
	 * names the file left in place.
	 *
	 * @return iterable<string,array{?\Throwable,?\Throwable,string,string,?class-string}>
	 */
	public static function lookupsThatFail(): iterable {
		yield 'the id does not resolve' => [new NotFoundException('gone'), null, 'info', 'Left a claimed .pad file alone: its id did not resolve.', null];
		yield 'the lookup fails' => [new \RuntimeException('storage down'), null, 'warning', 'Left a .pad file in place: it could not be looked up or read.', \RuntimeException::class];
		yield 'the read fails' => [null, new LockedException('/Created.pad'), 'warning', 'Left a .pad file in place: it could not be looked up or read.', LockedException::class];
		// Not taken for an empty file, which a claim that wrote nothing would remove.
		yield 'the read gives no string' => [null, null, 'warning', 'Left a .pad file in place: it could not be looked up or read.', \TypeError::class];
	}

	/** @param ?class-string $error */
	#[DataProvider('lookupsThatFail')]
	public function testLeavesAFileItCannotLookUpOrRead(?\Throwable $lookup, ?\Throwable $read, string $level, string $message, ?string $error): void {
		$found = $this->createMock(File::class);
		if ($read === null) {
			$found->method('getContent')->willReturn(null);
		} else {
			$found->method('getContent')->willThrowException($read);
		}
		$found->expects($this->never())->method('delete');
		$resolver = $this->createMock(UserNodeResolver::class);
		if ($lookup === null) {
			$resolver->method('resolveUserFileNodeById')->with('alice', 4242)->willReturn($found);
		} else {
			$resolver->method('resolveUserFileNodeById')->willThrowException($lookup);
		}
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($level === 'info' ? 'warning' : 'info');
		$logger->expects($this->once())->method($level)->with(
			$message,
			$this->callback(static fn (array $context): bool => array_diff_key($context, ['error_message' => true, 'error_origin' => true])
				=== ['app' => 'etherpad_nextcloud', 'uid' => 'alice', 'fileId' => 4242, 'file' => '/Created.pad'] + ($error === null ? [] : ['error' => $error])),
		);

		$this->writerWith($resolver, $logger)->deleteIfUnchanged(new CreatedFileClaim('alice', 4242), ['file' => '/Created.pad']);
	}

	/** Named with the claimed file's id, not with whatever the node found by that id answers by then. */
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
