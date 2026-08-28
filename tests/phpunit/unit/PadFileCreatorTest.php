<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Service\PadFileCreator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadFileCreatorTest extends TestCase {
	public function testReturnsTheNewFileWhenTheNameIsFree(): void {
		$file = $this->emptyFile();
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')->with('Pad.pad')->willReturn(false);
		$folder->expects($this->once())
			->method('newFile')
			->with('Pad.pad')
			->willReturn($file);

		$this->assertSame($file, $this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad'));
	}

	public function testRefusesWhenTheTargetAlreadyExists(): void {
		$folder = $this->folderWithoutTheFile();
		$folder->method('nodeExists')->with('Pad.pad')->willReturn(true);
		$folder->expects($this->never())->method('newFile');

		$this->expectException(PadFileAlreadyExistsException::class);
		$this->expectExceptionMessage('Target .pad file already exists.');
		$this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad');
	}

	/**
	 * Something outside our lock — the Files UI, a sync client — took the
	 * name between the check and the create.
	 */
	public function testTreatsALostRaceAsAConflict(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')
			->with('Pad.pad')
			->willReturnOnConsecutiveCalls(false, true);
		$folder->method('newFile')->willThrowException(new \RuntimeException('storage error'));

		$this->expectException(PadFileAlreadyExistsException::class);
		$this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testPropagatesAGenuineStorageFailure(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willThrowException(new \RuntimeException('disk full'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not create .pad file.');
		$this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad');
	}

	/**
	 * Concurrent creates of one name used to answer 500 — measured six out of
	 * six against a real instance, twice with no file created at all. The
	 * loser is now told the name is taken, which is what happened.
	 */
	public function testTurnsAwayASecondCreateOfTheSameName(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->expects($this->never())->method('newFile');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('Pad.pad'));
		$locking->expects($this->never())->method('releaseLock');

		$this->expectException(PadFileAlreadyExistsException::class);
		(new PadFileCreator($this->createMock(IRootFolder::class), $locking, $this->createMock(IFilenameValidator::class), $this->createMock(LoggerInterface::class)))
			->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testReleasesTheLockEvenWhenTheCreateFails(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')->willReturn(true);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		try {
			(new PadFileCreator($this->createMock(IRootFolder::class), $locking, $this->createMock(IFilenameValidator::class), $this->createMock(LoggerInterface::class)))
				->createUserFileInFolder($folder, 'Pad.pad');
			$this->fail('Expected the create to be refused.');
		} catch (PadFileAlreadyExistsException) {
			// expected
		}
	}

	/** Two different targets must not share one lock. */
	public function testLocksEachTargetSeparately(): void {
		$keys = [];
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(
			static function (string $key) use (&$keys): void {
				$keys[] = $key;
			},
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getId')->willReturn(7);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->emptyFile());

		$creator = new PadFileCreator($this->createMock(IRootFolder::class), $locking, $this->createMock(IFilenameValidator::class), $this->createMock(LoggerInterface::class));
		$creator->createUserFileInFolder($folder, 'One.pad');
		$creator->createUserFileInFolder($folder, 'Two.pad');

		$this->assertCount(2, $keys);
		$this->assertNotSame($keys[0], $keys[1]);
		// oc_file_locks.key is varchar(64).
		foreach ($keys as $key) {
			$this->assertLessThanOrEqual(64, strlen($key));
		}
	}

	/**
	 * A shared folder has a different path for its owner than for everyone it
	 * is shared with. Keying the lock on the path would give the same target
	 * two locks — in exactly the folder where two people are most likely to
	 * create the same pad at once.
	 */
	public function testTheSameFolderLocksTheSameWhicheverPathItIsSeenUnder(): void {
		$keys = [];
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(
			static function (string $key) use (&$keys): void {
				$keys[] = $key;
			},
		);

		$creator = new PadFileCreator($this->createMock(IRootFolder::class), $locking, $this->createMock(IFilenameValidator::class), $this->createMock(LoggerInterface::class));
		$creator->createUserFileInFolder($this->folderSeenAs('/alice/files/Team', 7), 'Pad.pad');
		$creator->createUserFileInFolder($this->folderSeenAs('/bob/files/Shared/Team', 7), 'Pad.pad');
		$creator->createUserFileInFolder($this->folderSeenAs('/alice/files/Other', 8), 'Pad.pad');

		$this->assertSame($keys[0], $keys[1], 'same folder, same lock');
		$this->assertNotSame($keys[0], $keys[2], 'different folders, different locks');
	}

	private function folderSeenAs(string $path, int $fileId): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getId')->willReturn($fileId);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->emptyFile());
		return $folder;
	}

	/**
	 * The structural checks elsewhere cover empty names, paths, `.` and
	 * `..`. Everything past that is per instance — configured forbidden
	 * characters and names, control characters, what the storage allows —
	 * and asking turns a 500 from deep in the storage into a sentence.
	 */
	public function testRefusesANameThisInstanceForbids(): void {
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('validateFilename')
			->with('COM1.pad')
			->willThrowException(new InvalidPathException('"COM1" is a reserved name'));

		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('newFile');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$this->expectException(InvalidPadNameException::class);
		$this->expectExceptionMessage('"COM1" is a reserved name');

		(new PadFileCreator(
			$this->createMock(IRootFolder::class),
			$locking,
			$validator,
			$this->createMock(LoggerInterface::class),
		))->createUserFileInFolder($folder, 'COM1.pad');
	}

	/**
	 * The instance-wide rules are not the whole answer: the storage behind
	 * this particular folder can add its own, and an external or mounted
	 * folder often does. Asking only the validator would still let the name
	 * fail deep in the storage.
	 */
	public function testRefusesANameTheTargetStorageForbids(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->expects($this->once())
			->method('verifyPath')
			->with('Team', 'Report:2026.pad')
			->willThrowException(new InvalidPathException('File name contains at least one invalid character'));

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($storage);
		$folder->method('getInternalPath')->willReturn('Team');
		$folder->expects($this->never())->method('newFile');

		$this->expectException(InvalidPadNameException::class);
		$this->expectExceptionMessage('File name contains at least one invalid character');

		$this->buildCreator()->createUserFileInFolder($folder, 'Report:2026.pad');
	}

	/** A folder that cannot name a storage is not a reason to refuse. */
	public function testCreatesWhenTheFolderCannotBeAskedAboutItsStorage(): void {
		$file = $this->emptyFile();
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(7);
		$folder->method('getStorage')->willThrowException(new \RuntimeException('no storage here'));
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($file);

		$this->assertSame($file, $this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad'));
	}

	private function emptyFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(0);
		return $file;
	}

	private function folderWithoutTheFile(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/alice/files');
		return $folder;
	}

	private function buildCreator(): PadFileCreator {
		return new PadFileCreator(
			$this->createMock(IRootFolder::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(IFilenameValidator::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
