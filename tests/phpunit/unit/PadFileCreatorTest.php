<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotFoundException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Service\PadFileCreator;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\Lock\ILockingProvider;
use OCP\IL10N;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadFileCreatorTest extends TestCase {
	/**
	 * A path names the folder to create in: one that is not there, one the
	 * user may not look into, or one that is no folder, is a 404 for the path endpoints, as for the one that names
	 * the folder by id - not a server error.
	 */
	public function testRefusesAPathWhoseFolderIsNotThere(): void {
		$cases = [
			'no such folder' => new NotFoundException('Missing'),
			'one the user may not look into' => new NotPermittedException('Missing'),
			'a file by that name' => $this->createMock(File::class),
		];
		foreach ($cases as $case => $found) {
			$home = $this->creatableFolder();
			$found instanceof \Throwable
				? $home->method('get')->with('Missing')->willThrowException($found)
				: $home->method('get')->with('Missing')->willReturn($found);
			try {
				$this->buildCreator(root: $this->rootWithHome($home))->createUserFile('alice', '/Missing/Notes.pad');
				$this->fail($case . ': created');
			} catch (PadParentFolderNotFoundException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * A folder the user may not create in - a read-only share - is a 403,
	 * as for the endpoint that names the folder by id, before the storage
	 * is asked.
	 */
	public function testRefusesAPathWhoseFolderTakesNoNewFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn(false);
		$folder->expects($this->never())->method('newFile');
		$home = $this->creatableFolder();
		$home->method('get')->with('Shared')->willReturn($folder);

		$this->expectException(PadParentFolderNotWritableException::class);
		$this->buildCreator(root: $this->rootWithHome($home))->createUserFile('alice', '/Shared/Notes.pad');
	}

	/**
	 * The folder named by id gets the same refusal, from the same place:
	 * before its name rules are asked or anything is locked or written.
	 */
	public function testRefusesAFolderThatTakesNoNewFileBeforeAskingAnything(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn(false);
		$folder->expects($this->never())->method('newFile');
		$folder->expects($this->never())->method('getStorage');
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->expects($this->never())->method('validateFilename');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');

		$this->expectException(PadParentFolderNotWritableException::class);
		$this->buildCreator(locking: $locking, validator: $validator)->createUserFileInFolder($folder, 'Notes.pad');
	}

	/**
	 * A user whose files cannot be reached is answered as a folder not
	 * there, as a lookup of their files by path is (UserNodeResolver).
	 */
	public function testRefusesAPathWhenTheUsersFilesCannotBeReached(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willThrowException(new NotPermittedException('home storage unavailable'));

		$this->expectException(PadParentFolderNotFoundException::class);
		$this->buildCreator(root: $root)->createUserFile('alice', '/Team/Notes.pad');
	}

	public function testCreatesAtTheRootOfTheUsersFiles(): void {
		$file = $this->emptyFile();
		$home = $this->creatableFolder();
		$home->method('getPath')->willReturn('/alice/files');
		$home->method('nodeExists')->with('Notes.pad')->willReturn(false);
		$home->expects($this->never())->method('get');
		$home->expects($this->once())->method('newFile')->with('Notes.pad')->willReturn($file);

		$this->assertSame($file, $this->buildCreator(root: $this->rootWithHome($home))->createUserFile('alice', '/Notes.pad'));
	}

	public function testCreatesAtAPathWhoseFolderTakesIt(): void {
		$file = $this->emptyFile();
		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files/Team');
		$folder->method('nodeExists')->with('Notes.pad')->willReturn(false);
		$folder->expects($this->once())->method('newFile')->with('Notes.pad')->willReturn($file);
		$home = $this->creatableFolder();
		$home->method('get')->with('Team')->willReturn($folder);

		$this->assertSame($file, $this->buildCreator(root: $this->rootWithHome($home))->createUserFile('alice', '/Team/Notes.pad'));
	}

	public function testReturnsTheNewFileWhenTheNameIsFree(): void {
		$file = $this->emptyFile();
		$folder = $this->creatableFolder();
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
		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')
			->with('Pad.pad')
			->willReturnOnConsecutiveCalls(false, true);
		$folder->method('newFile')->willThrowException(new \RuntimeException('storage error'));

		$this->expectException(PadFileAlreadyExistsException::class);
		$this->buildCreator()->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testPropagatesAGenuineStorageFailure(): void {
		$folder = $this->creatableFolder();
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
		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->expects($this->never())->method('newFile');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('Pad.pad'));
		$locking->expects($this->never())->method('releaseLock');

		$this->expectException(PadFileAlreadyExistsException::class);
		($this->buildCreator(locking: $locking))
			->createUserFileInFolder($folder, 'Pad.pad');
	}

	public function testReleasesTheLockEvenWhenTheCreateFails(): void {
		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('nodeExists')->willReturn(true);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		try {
			($this->buildCreator(locking: $locking))
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

		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getId')->willReturn(7);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($this->emptyFile());

		$creator = $this->buildCreator(locking: $locking);
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

		$creator = $this->buildCreator(locking: $locking);
		$creator->createUserFileInFolder($this->folderSeenAs('/alice/files/Team', 7), 'Pad.pad');
		$creator->createUserFileInFolder($this->folderSeenAs('/bob/files/Shared/Team', 7), 'Pad.pad');
		$creator->createUserFileInFolder($this->folderSeenAs('/alice/files/Other', 8), 'Pad.pad');

		$this->assertSame($keys[0], $keys[1], 'same folder, same lock');
		$this->assertNotSame($keys[0], $keys[2], 'different folders, different locks');
	}

	private function folderSeenAs(string $path, int $fileId): Folder {
		$folder = $this->creatableFolder();
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
	 *
	 * The validator's refusal is Nextcloud's own and translated, so it is
	 * shown: "\"COM1\" is a reserved name" tells the user what to change,
	 * "Invalid pad name" does not.
	 */
	public function testPassesTheValidatorsReasonThrough(): void {
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('validateFilename')
			->willThrowException(new InvalidPathException('"COM1" is a reserved name'));

		$this->expectException(InvalidPadNameException::class);
		$this->expectExceptionMessage('"COM1" is a reserved name');

		$this->buildCreator(validator: $validator)
			->createUserFileInFolder($this->creatableFolder(), 'COM1.pad');
	}

	/**
	 * A storage's message is never shown. These exception classes are
	 * public, so a storage app may throw one carrying a mount point, a
	 * bucket name or a driver error — and this reaches a browser.
	 */
	public function testDoesNotForwardAStoragesOwnMessage(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('verifyPath')
			->willThrowException(new InvalidPathException('smb://fileserver.internal/share denied 0xC0000022'));

		$this->expectException(InvalidPadNameException::class);
		$this->expectExceptionMessage('That file name is not allowed on this server.');

		$this->buildCreator()->createUserFileInFolder($this->folderOn($storage), 'Odd.pad');
	}

	/**
	 * Some storages only judge a name when the write happens, so asking
	 * beforehand cannot be complete — and that answer must still be a 400.
	 */
	public function testTreatsALateRefusalFromTheWriteAsANameProblem(): void {
		$folder = $this->creatableFolder();
		$folder->method('getId')->willReturn(7);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willThrowException(new InvalidPathException('rejected by the backend'));

		$this->expectException(InvalidPadNameException::class);
		$this->expectExceptionMessage('That file name is not allowed on this server.');

		$this->buildCreator()->createUserFileInFolder($folder, 'COM1.pad');
	}

	/**
	 * A storage that cannot answer is not a verdict on the name. The create
	 * goes on and newFile() decides — but it is logged, or the check could
	 * stop running with nothing to show for it.
	 */
	public function testCarriesOnWhenTheStorageCannotAnswer(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('verifyPath')->willThrowException(new StorageNotAvailableException('mount is down'));

		$file = $this->emptyFile();
		$folder = $this->folderOn($storage);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturn($file);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not ask the storage'), $this->anything());

		$creator = $this->buildCreator(logger: $logger);

		$this->assertSame($file, $creator->createUserFileInFolder($folder, 'Pad.pad'));
	}

	private function folderOn(IStorage $storage): Folder {
		$folder = $this->creatableFolder();
		$folder->method('getId')->willReturn(7);
		$folder->method('getStorage')->willReturn($storage);
		$folder->method('getInternalPath')->willReturn('Team');
		return $folder;
	}


	/** A folder the user may create in, as the tests not about that need. */
	private function creatableFolder(): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn(true);
		return $folder;
	}

	private function emptyFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(0);
		return $file;
	}

	private function folderWithoutTheFile(): Folder {
		$folder = $this->creatableFolder();
		$folder->method('getPath')->willReturn('/alice/files');
		return $folder;
	}

	private function rootWithHome(Folder $home): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($home);
		return $root;
	}

	private function buildCreator(
		?ILockingProvider $locking = null,
		?IFilenameValidator $validator = null,
		?LoggerInterface $logger = null,
		?IRootFolder $root = null,
	): PadFileCreator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PadFileCreator(
			new UserNodeResolver($root ?? $this->createMock(IRootFolder::class)),
			$locking ?? $this->createMock(ILockingProvider::class),
			$validator ?? $this->createMock(IFilenameValidator::class),
			$l10n,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
