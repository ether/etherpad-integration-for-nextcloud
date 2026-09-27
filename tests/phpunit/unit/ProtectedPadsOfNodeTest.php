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
		$pads = new ProtectedPadsOfNode($table, $mimeTypes);

		$this->assertSame(['pad-11', 'pad-21', 'pad-31'], $pads->of($this->folder(10)));
		$this->assertSame(['pad-31'], $pads->of($this->folder(30)));
		$this->assertSame(['pad-41'], $pads->of($this->file(41)));
		$this->assertSame([], $pads->of($this->file(12)), 'a public pad');
		$this->assertSame([], $pads->of($this->file(99)), 'a file without a row');
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
