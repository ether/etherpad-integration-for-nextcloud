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

		$this->assertSame(['pad-11', 'pad-21', 'pad-31'], $pads->of($this->folder(10)));
		$this->assertSame(['pad-31'], $pads->of($this->folder(30)));
		$this->assertSame(['pad-41'], $pads->of($this->file(41)));
		$this->assertSame([], $pads->of($this->file(12)), 'a public pad');
		$this->assertSame([], $pads->of($this->file(99)), 'a file without a row');
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

		$this->assertSame([], (new ProtectedPadsOfNode($table, $mimeTypes, $this->createMock(LoggerInterface::class)))->of($this->folder(10)));
	}

	/**
	 * A walk in a delete's request is bounded: past a hundred pads - more
	 * than a delete can take the sessions of - it stops, with a line.
	 */
	public function testAWalkStopsPastAHundredPads(): void {
		$rows = [];
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 1000; $fileId < 1150; $fileId++) {
			$rows[] = ['file_id' => $fileId, 'pad_id' => 'pad-' . $fileId, 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100];
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::TEXT, 'storage' => 1, 'path' => 'files/' . $fileId];
		}
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->willReturn(self::DIRECTORY);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('A folder deleted holds more than a delete takes the sessions of; the rest expire on their own.', $this->anything());

		$table = new InMemoryBindingTable($rows, $cached);
		$pads = (new ProtectedPadsOfNode($table, $mimeTypes, $logger))->of($this->folder(10));

		$this->assertCount(100, $pads);
		$this->assertLessThanOrEqual(101, max($table->read), 'no query reads past the room');
	}

	/**
	 * The walk's queries are bounded too: a folder with more folders in it
	 * than the walk has room for reads no more of them than that.
	 */
	public function testAWideFolderIsReadNoFurtherThanTheWalkHasRoomFor(): void {
		$rows = [['file_id' => 5, 'pad_id' => 'g.ABCDEFGHIJKLMNOP$elsewhere', 'access_mode' => BindingService::ACCESS_PROTECTED, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 100]];
		$cached = [['fileid' => 10, 'parent' => 1, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10']];
		for ($fileId = 100; $fileId < 150; $fileId++) {
			$cached[] = ['fileid' => $fileId, 'parent' => 10, 'mimetype' => self::DIRECTORY, 'storage' => 1, 'path' => 'files/10/' . $fileId];
		}
		$table = new InMemoryBindingTable($rows, $cached);
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->willReturn(self::DIRECTORY);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$pads = new class($table, $mimeTypes, $logger) extends ProtectedPadsOfNode {
			protected const MAX_FOLDERS = 10;
		};

		$pads->of($this->folder(10));

		$this->assertLessThanOrEqual(11, max($table->read), 'no query reads past the room');
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
