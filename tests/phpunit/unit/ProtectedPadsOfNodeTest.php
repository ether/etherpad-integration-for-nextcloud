<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\ProtectedPadsOfNode;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The protected pads a node takes along: a file's own, or those of every
 * file under a folder, however deep - by the files' rows, whatever they
 * are called. A public pad has no sessions to take, and a row seen deleted
 * already is on its way.
 */
class ProtectedPadsOfNodeTest extends TestCase {
	private const DIRECTORY = 2;
	private const TEXT = 9;

	public function testFindsTheProtectedPadsANodeTakesAlong(): void {
		$row = static fn (int $fileId, string $accessMode, string $state = BindingService::STATE_ACTIVE): array => ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => $accessMode, 'state' => $state, 'deleted_at' => null, 'updated_at' => 100];
		$cached = static fn (int $fileId, int $parent, int $mimetype = self::TEXT): array => ['fileid' => $fileId, 'parent' => $parent, 'mimetype' => $mimetype, 'storage' => 1, 'path' => 'files/' . $fileId];
		$table = new InMemoryBindingTable([
			$row(11, BindingService::ACCESS_PROTECTED),
			$row(12, BindingService::ACCESS_PUBLIC),
			$row(21, BindingService::ACCESS_PROTECTED),
			$row(22, BindingService::ACCESS_PROTECTED, BindingService::STATE_PENDING_DELETE),
			$row(31, BindingService::ACCESS_PROTECTED),
			$row(41, BindingService::ACCESS_PROTECTED),
		], [
			$cached(10, 1, self::DIRECTORY),
			$cached(11, 10),
			$cached(12, 10),
			$cached(20, 10, self::DIRECTORY),
			// A .pad renamed: its row counts, not its name.
			$cached(21, 20),
			$cached(22, 20),
			$cached(30, 20, self::DIRECTORY),
			$cached(31, 30),
			// Beside the folder, not under it.
			$cached(41, 1),
		]);
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->with('httpd/unix-directory')->willReturn(self::DIRECTORY);
		$pads = new ProtectedPadsOfNode($table, $mimeTypes, $this->createMock(LoggerInterface::class));

		$this->assertSame(['pad-11', 'pad-21', 'pad-31'], $pads->of($this->folder(10))->pads);
		$this->assertSame(['pad-31'], $pads->of($this->folder(30))->pads);
		$this->assertSame(['pad-41'], $pads->of($this->file(41))->pads);
		$this->assertSame([], $pads->of($this->file(12))->pads, 'a public pad');
		$this->assertSame([], $pads->of($this->file(99))->pads, 'a file without a row');
		$this->assertSame(1, end($table->limits), 'a file has one row at most');
	}

	/**
	 * An instance without an active protected pad takes no walk down a
	 * folder: one look at the binding table says there is nothing to find.
	 */
	public function testAFolderIsNotWalkedWithoutAProtectedPad(): void {
		$table = new InMemoryBindingTable([
			['file_id' => 11, 'pad_id' => 'pad-11', 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100],
			['file_id' => 12, 'pad_id' => 'pad-12', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 90, 'updated_at' => 90],
		], [['fileid' => 11, 'parent' => 10, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/11']]);
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->expects($this->never())->method('getId');

		$this->assertSame([], (new ProtectedPadsOfNode($table, $mimeTypes, $this->createMock(LoggerInterface::class)))->of($this->folder(10))->pads);
	}

	/**
	 * A walk in a delete's request is bounded: past a hundred pads - more
	 * than a delete can take the sessions of - it stops, with a line.
	 */
	public function testAWalkStopsPastAHundredPads(): void {
		$table = $this->folderOfPads(150);
		$logger = $this->createMock(LoggerInterface::class);
		// The line counts the pads found, one past those taken.
		$logger->expects($this->once())->method('info')->with('A folder deleted holds more than a delete takes the sessions of; a background job looks for the rest.', $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['pads'] === 101 && $context['folders'] === 1));

		$found = $this->walk($table, $logger)->of($this->folder(10));
		$pads = $found->pads;
		$this->assertTrue($found->stoppedShort);

		$this->assertCount(100, $pads);
		$this->assertLessThanOrEqual(101, max($table->returned), 'no query returns more than one past the room');
		$this->assertCount(2, $table->returned, 'no folders asked for once the pads are past the cap');
	}

	/** The hundred-and-first pad a level down still tells that some are left. */
	public function testAPadPastTheHundredOnADeeperLevelIsTold(): void {
		$rows = [$this->protectedRow(2000)];
		$cached = [
			['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10'],
			['fileid' => 20, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/20'],
			['fileid' => 2000, 'parent' => 20, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/10/20/2000'],
		];
		for ($fileId = 1000; $fileId < 1100; $fileId++) {
			$rows[] = $this->protectedRow($fileId);
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['pads'] === 101 && $context['folders'] === 2));

		$found = $this->walk(new InMemoryBindingTable($rows, $cached), $logger)->of($this->folder(10));
		$this->assertCount(100, $found->pads);
		$this->assertTrue($found->stoppedShort);
	}

	/**
	 * A level of more than CHUNK folders is asked about CHUNK at a time:
	 * with the pads past the cap in the first chunk, the second is never
	 * asked about.
	 */
	public function testALevelIsAskedAboutInChunks(): void {
		$rows = [];
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 100; $fileId < 700; $fileId++) {
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		for ($fileId = 1000; $fileId < 1101; $fileId++) {
			$rows[] = $this->protectedRow($fileId);
			$cached[] = ['fileid' => $fileId, 'parent' => 100, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/10/100/' . $fileId];
		}
		$table = new InMemoryBindingTable($rows, $cached);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['pads'] === 101 && $context['folders'] === 501));

		$this->assertCount(100, $this->walk($table, $logger)->of($this->folder(10))->pads);
		$this->assertCount(4, $table->returned, 'the look at the table, both queries of the first level, the pads of the first chunk');
	}

	public function testAHundredPadsAreTakenWhole(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$found = $this->walk($this->folderOfPads(100), $logger)->of($this->folder(10));
		$this->assertCount(100, $found->pads);
		$this->assertFalse($found->stoppedShort);
	}

	/** Folder 10 with $count files of protected pads in it. */
	private function folderOfPads(int $count): InMemoryBindingTable {
		$rows = [];
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 1000; $fileId < 1000 + $count; $fileId++) {
			$rows[] = $this->protectedRow($fileId);
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/' . $fileId];
		}
		return new InMemoryBindingTable($rows, $cached);
	}

	private function walk(InMemoryBindingTable $table, LoggerInterface $logger): ProtectedPadsOfNode {
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->willReturn(self::DIRECTORY);
		return new ProtectedPadsOfNode($table, $mimeTypes, $logger);
	}

	/**
	 * MAX_FOLDERS lowered to ten stands in for the real cap: of fifty
	 * folders in one, the walk takes ten, the folder deleted among them.
	 */
	public function testAWalkTakesNoMoreFoldersThanItHasRoomFor(): void {
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 100; $fileId < 150; $fileId++) {
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		$table = new InMemoryBindingTable([$this->protectedRow(5)], $cached);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['folders'] === 10));

		$found = $this->tenFolderWalk($table, $logger)->of($this->folder(10));

		$this->assertSame([], $found->pads);
		$this->assertTrue($found->stoppedShort, 'a walk stopped short with no pad found says so too');

		$this->assertLessThanOrEqual(10, max($table->returned), 'no query returns more than one past the room');
		// The look at the table, then pads and folders of the first level, and only pads of the second.
		$this->assertCount(4, $table->returned, 'no folders asked for once some are left');
	}

	/**
	 * The cap reached on a deeper level: the folder deleted, nine in it
	 * and one in the last of those. The walk takes the first ten and
	 * leaves the pad in the eleventh.
	 */
	public function testAWalkStopsAtTheCapOnADeeperLevel(): void {
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 100; $fileId < 109; $fileId++) {
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		$cached[] = ['fileid' => 300, 'parent' => 108, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/108/300'];
		$cached[] = ['fileid' => 301, 'parent' => 300, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/10/108/300/301'];
		$table = new InMemoryBindingTable([$this->protectedRow(301)], $cached);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['folders'] === 10));

		$found = $this->tenFolderWalk($table, $logger)->of($this->folder(10));
		$this->assertSame([], $found->pads);
		$this->assertTrue($found->stoppedShort);
	}

	public function testATreeThatFitsTheWalkExactlyIsWalkedWhole(): void {
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 100; $fileId < 109; $fileId++) {
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		$cached[] = ['fileid' => 200, 'parent' => 108, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/10/108/200'];
		$table = new InMemoryBindingTable([$this->protectedRow(200)], $cached);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$found = $this->tenFolderWalk($table, $logger)->of($this->folder(10));
		$this->assertSame(['pad-200'], $found->pads);
		$this->assertFalse($found->stoppedShort);
	}

	/** @return array<string,mixed> */
	private function protectedRow(int $fileId): array {
		return ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
	}

	private function tenFolderWalk(InMemoryBindingTable $table, LoggerInterface $logger): ProtectedPadsOfNode {
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->willReturn(self::DIRECTORY);
		return new class($table, $mimeTypes, $logger) extends ProtectedPadsOfNode {
			protected const MAX_FOLDERS = 10;
		};
	}

	private function folder(int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		return $folder;
	}

	private function file(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}
}
