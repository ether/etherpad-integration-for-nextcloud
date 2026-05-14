<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Service\PadFileCreator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

class PadFileCreatorTest extends TestCase {
	public function testCreateUserFileInFolderReturnsNewFileWhenTargetDoesNotExist(): void {
		$file = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->with('Pad.pad')->willReturn(false);
		$folder->expects($this->once())
			->method('newFile')
			->with('Pad.pad')
			->willReturn($file);

		$creator = new PadFileCreator($this->createMock(IRootFolder::class));

		$this->assertSame($file, $creator->createUserFileInFolder($folder, 'Pad.pad'));
	}

	public function testCreateUserFileInFolderRefusesWhenTargetAlreadyExists(): void {
		// Regression: some Nextcloud storage backends silently return the
		// existing node from newFile() instead of throwing, which previously
		// caused us to overwrite an existing .pad file. The pre-check must
		// stop the create before newFile() is even called.
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->with('Pad.pad')->willReturn(true);
		$folder->expects($this->never())->method('newFile');

		$creator = new PadFileCreator($this->createMock(IRootFolder::class));

		$this->expectException(PadFileAlreadyExistsException::class);
		$this->expectExceptionMessage('Target .pad file already exists.');
		$creator->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testCreateUserFileInFolderTreatsNewFileExceptionWithExistingNodeAsConflict(): void {
		// Race-condition path: nodeExists was false at pre-check, but the file
		// appeared between the check and newFile(). We still want a clean 409.
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')
			->with('Pad.pad')
			->willReturnOnConsecutiveCalls(false, true);
		$folder->method('newFile')->willThrowException(new \RuntimeException('storage error'));

		$creator = new PadFileCreator($this->createMock(IRootFolder::class));

		$this->expectException(PadFileAlreadyExistsException::class);
		$creator->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testCreateUserFileInFolderPropagatesGenericRuntimeErrors(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willThrowException(new \RuntimeException('disk full'));

		$creator = new PadFileCreator($this->createMock(IRootFolder::class));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not create .pad file.');
		$creator->createUserFileInFolder($folder, 'Pad.pad');
	}
}
