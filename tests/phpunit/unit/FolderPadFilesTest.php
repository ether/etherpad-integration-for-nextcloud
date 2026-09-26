<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\FolderPadFiles;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
use OCP\Files\IMimeTypeLoader;
use PHPUnit\Framework\TestCase;

/**
 * The bound .pad files under a folder, found by walking down its tree in
 * the file cache: however deep, whatever the case of `.pad`, not beside
 * it, not what is no pad or has no row.
 */
class FolderPadFilesTest extends TestCase {
	private const DIR = 2;
	private const PAD = 7;
	private const TEXT = 8;

	public function testWalksDownTheTree(): void {
		$files = [
			self::node(100, 50, 'Te_m', self::DIR),
			self::node(1, 100, 'a.pad', self::PAD),
			self::node(102, 100, 'sub', self::DIR),
			self::node(2, 102, 'B.PAD', self::PAD),
			self::node(103, 102, 'deeper', self::DIR),
			self::node(3, 103, 'c.pad', self::TEXT),
			self::node(4, 100, 'notes.txt', self::TEXT),
			self::node(5, 100, 'unbound.pad', self::PAD),
			self::node(104, 50, 'Te_mwork', self::DIR),
			self::node(6, 104, 'beside.pad', self::PAD),
			self::node(7, 50, 'top.pad', self::PAD),
		];
		$rows = array_map(static fn (int $id): array => ['file_id' => $id, 'pad_id' => 'pad-' . $id, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 1], [1, 2, 3, 4, 6, 7]);
		$folderPads = $this->folderPads(new InMemoryBindingTable($rows, $files));

		$this->assertSame([1, 2, 3], $folderPads->under(100), 'its own, a level down and two, a .pad by name whatever its type');
		$this->assertSame([3], $folderPads->under(103));
		$this->assertSame([7, 1, 6, 2, 3], $folderPads->under(50), 'the tree above, level by level');
		$this->assertSame([], $folderPads->under(999));
	}

	/** A level with more folders than a query takes is taken in chunks. */
	public function testAWideLevelInChunks(): void {
		$files = [];
		$rows = [];
		for ($i = 1; $i <= 1200; $i++) {
			$files[] = self::node(10000 + $i, 1, 'd' . $i, self::DIR);
			$files[] = self::node($i, 10000 + $i, 'p' . $i . '.pad', self::PAD);
			$rows[] = ['file_id' => $i, 'pad_id' => 'pad-' . $i, 'access_mode' => BindingService::ACCESS_PUBLIC, 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 1];
		}

		$this->assertSame(range(1, 1200), $this->folderPads(new InMemoryBindingTable($rows, $files))->under(1));
	}

	private function folderPads(InMemoryBindingTable $db): FolderPadFiles {
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('getId')->with('httpd/unix-directory')->willReturn(self::DIR);
		return new FolderPadFiles($db, $mimeTypes, new BindingService($db, new FixedClock()));
	}

	/** @return array<string,mixed> */
	private static function node(int $id, int $parent, string $name, int $mimetype): array {
		return ['fileid' => $id, 'storage' => 1, 'path' => 'files/' . $name, 'parent' => $parent, 'name' => $name, 'mimetype' => $mimetype];
	}
}
