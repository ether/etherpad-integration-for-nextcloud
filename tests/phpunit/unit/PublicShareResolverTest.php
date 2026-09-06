<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicShareResolverTest extends TestCase {
	public function testResolveShareReturnsCachedShareWithoutManagerLookup(): void {
		$cached = $this->createMock(IShare::class);
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('getShareByToken');

		$this->assertSame($cached, (new PublicShareResolver($manager, new PathNormalizer()))->resolveShare('token', $cached));
	}

	public function testResolveShareMapsMissingToken(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getShareByToken')->willThrowException(new ShareNotFound());

		$this->expectException(InvalidShareTokenException::class);
		$this->expectExceptionMessage('This share link is invalid or has expired.');

		(new PublicShareResolver($manager, new PathNormalizer()))->resolveShare('token');
	}

	public function testResolvePadFileReturnsSingleFileShare(): void {
		$file = $this->padFile('Shared.pad', 42);
		$share = $this->share($file, Constants::PERMISSION_READ);

		$resolved = $this->buildResolver()->resolvePadFile($share, '', 'token');

		$this->assertSame($file, $resolved->node);
		$this->assertTrue($resolved->readOnly);
		$this->assertSame('Shared.pad', $resolved->name);
	}

	public function testResolvePadFileReturnsWritableFolderSelection(): void {
		$file = $this->padFile('Shared.pad', 42);
		$file->method('isUpdateable')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('get')->with('Folder/Shared.pad')->willReturn($file);
		$share = $this->share($folder, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$resolved = $this->buildResolver()->resolvePadFile($share, '/Folder/Shared.pad', 'token');

		$this->assertSame($file, $resolved->node);
		$this->assertFalse($resolved->readOnly);
	}

	public function testResolvePadFileRejectsShareWithoutReadPermission(): void {
		$share = $this->share($this->padFile('Shared.pad', 42), Constants::PERMISSION_UPDATE);

		$this->expectException(ShareReadForbiddenException::class);
		$this->expectExceptionMessage('This share link does not allow reading files.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileMapsMissingShareNode(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willThrowException(new NotFoundException('missing'));

		$this->expectException(ShareItemUnavailableException::class);
		$this->expectExceptionMessage('This shared item is no longer available.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileRejectsFolderShareWithoutSelectedFile(): void {
		$share = $this->share($this->createMock(Folder::class), Constants::PERMISSION_READ);

		$this->expectException(NoShareFileSelectedException::class);
		$this->expectExceptionMessage('No .pad file selected. Open a .pad file from this shared folder.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileRejectsInvalidFolderPath(): void {
		$share = $this->share($this->createMock(Folder::class), Constants::PERMISSION_READ);

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildResolver()->resolvePadFile($share, '../Shared.pad', 'token');
	}

	public function testResolvePadFilePreservesInvalidPathPreviousException(): void {
		$share = $this->share($this->createMock(Folder::class), Constants::PERMISSION_READ);

		try {
			$this->buildResolver()->resolvePadFile($share, '../Shared.pad', 'token');
			$this->fail('Expected invalid share file path exception.');
		} catch (InvalidShareFilePathException $e) {
			$this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
		}
	}

	public function testResolvePadFileMapsMissingFolderFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('missing'));
		$share = $this->share($folder, Constants::PERMISSION_READ);

		$this->expectException(ShareFileNotInShareException::class);
		$this->expectExceptionMessage('The selected file does not exist in this share.');

		$this->buildResolver()->resolvePadFile($share, 'Missing.pad', 'token');
	}

	public function testResolvePadFileRejectsFolderSelectionThatIsNotAFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($this->createMock(Folder::class));
		$share = $this->share($folder, Constants::PERMISSION_READ);

		$this->expectException(ShareFileNotInShareException::class);
		$this->expectExceptionMessage('The selected item is not a file.');

		$this->buildResolver()->resolvePadFile($share, 'NestedFolder', 'token');
	}

	public function testResolvePadFileRejectsNonPadFile(): void {
		$share = $this->share($this->padFile('Text.txt', 42), Constants::PERMISSION_READ);

		$this->expectException(NotAPadFileException::class);
		$this->expectExceptionMessage('The selected file is not a .pad document.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileFindsAFolderSelectionById(): void {
		$file = $this->padFile('A+B.pad', 42);
		$folder = $this->folderResolving($file, 'A+B.pad');
		$folder->expects($this->never())->method('get');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($file, $resolved->node);
	}

	public function testResolvePadFileMatchesTheIdAgainstASingleFileShare(): void {
		$file = $this->padFile('Shared.pad', 42);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($file, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($file, $resolved->node);
	}

	public function testResolvePadFileAcceptsAnIdAndNameThatAgreeOnASingleFileShare(): void {
		$file = $this->padFile('Shared.pad', 42);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($file, Constants::PERMISSION_READ), 'Shared.pad', 'token', 42);

		$this->assertSame($file, $resolved->node);
	}

	/** A file share has no path inside it, so the name is what a caller can name. */
	public function testResolvePadFileRefusesANameThatIsNotTheSharedFile(): void {
		$share = $this->share($this->padFile('Shared.pad', 42), Constants::PERMISSION_READ);

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('The file id and the file path name different files.');
		$this->buildResolver()->resolvePadFile($share, 'Other.pad', 'token', 42);
	}

	/** The token already names the file, so `file` is not an address there. */
	public function testResolvePadFileIgnoresAnOddPathOnASingleFileShareWithoutAnId(): void {
		$file = $this->padFile('Shared.pad', 42);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($file, Constants::PERMISSION_READ), '../Shared.pad', 'token');

		$this->assertSame($file, $resolved->node);
	}

	public static function failingCandidateCallProvider(): array {
		return [
			'getPermissions' => ['getPermissions', new NotFoundException('mount gone')],
			'isUpdateable' => ['isUpdateable', new InvalidPathException('bad path')],
		];
	}

	/** Every question asked about a candidate is one the OCP contract lets fail. */
	#[\PHPUnit\Framework\Attributes\DataProvider('failingCandidateCallProvider')]
	public function testResolvePadFileSkipsACandidateThatCannotAnswer(string $method, \Throwable $thrown): void {
		$broken = $this->padFile('A.pad', 42);
		$broken->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$broken->method('getPath')->willReturn('/owner/files/Share/Gone/A.pad');
		$broken->method('isUpdateable')->willReturn(false);
		$broken->method($method)->willThrowException($thrown);

		$reachable = $this->padFile('A.pad', 42);
		$reachable->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$reachable->method('getPath')->willReturn('/owner/files/Share/Here/A.pad');
		$reachable->method('isUpdateable')->willReturn(true);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$broken, $reachable]);
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => str_replace('/owner/files/Share', '', $path)
		);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($reachable, $resolved->node);
	}

	public function testResolvePadFileSkipsACandidateWhoseMountCannotBeResolved(): void {
		$unresolvable = $this->padFile('A.pad', 42);
		$unresolvable->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$unresolvable->method('getPath')->willReturn('/owner/files/Share/Gone/A.pad');
		$reachable = $this->padFile('A.pad', 42);
		$reachable->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$reachable->method('getPath')->willReturn('/owner/files/Share/Here/A.pad');

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$unresolvable, $reachable]);
		$folder->method('getRelativePath')->willReturnCallback(
			static function (string $path): string {
				if (str_contains($path, '/Gone/')) {
					throw new NotFoundException('mount gone');
				}
				return '/Here/A.pad';
			}
		);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($reachable, $resolved->node);
	}

	public function testResolvePadFileIsReadOnlyWhenTheNamedMountIsNotWritable(): void {
		$writable = $this->padFile('A.pad', 42);
		$writable->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$writable->method('getPath')->willReturn('/owner/files/Share/Write/A.pad');
		$writable->method('isUpdateable')->willReturn(true);
		$readOnly = $this->padFile('A.pad', 42);
		$readOnly->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$readOnly->method('getPath')->willReturn('/owner/files/Share/Read/A.pad');
		$readOnly->method('isUpdateable')->willReturn(false);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$writable, $readOnly]);
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => str_replace('/owner/files/Share', '', $path)
		);
		$share = $this->share($folder, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$resolved = $this->buildResolver()->resolvePadFile($share, 'Read/A.pad', 'token', 42);

		$this->assertSame($readOnly, $resolved->node);
		$this->assertTrue($resolved->readOnly);
	}

	/** Same rule when only a read-only mount answers an id-only request. */
	public function testResolvePadFileIsReadOnlyWhenTheOnlyMatchIsNotWritable(): void {
		$file = $this->padFile('A.pad', 42);
		$file->method('isUpdateable')->willReturn(false);
		$folder = $this->folderResolving($file, 'A.pad');
		$share = $this->share($folder, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$resolved = $this->buildResolver()->resolvePadFile($share, '', 'token', 42);

		$this->assertTrue($resolved->readOnly);
	}

	/** Otherwise the preference overrules the path and calls the pair contradictory. */
	public function testResolvePadFilePrefersTheNamedPathOverTheWritableMount(): void {
		$writable = $this->padFile('A.pad', 42);
		$writable->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$writable->method('getPath')->willReturn('/owner/files/Share/Write/A.pad');
		$writable->method('isUpdateable')->willReturn(true);
		$readOnly = $this->padFile('A.pad', 42);
		$readOnly->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$readOnly->method('getPath')->willReturn('/owner/files/Share/Read/A.pad');
		$readOnly->method('isUpdateable')->willReturn(false);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$writable, $readOnly]);
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => str_replace('/owner/files/Share', '', $path)
		);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'Read/A.pad', 'token', 42);

		$this->assertSame($readOnly, $resolved->node);
	}

	public function testResolvePadFilePrefersAWritableMatchOverAReadableOne(): void {
		$readOnly = $this->padFile('A.pad', 42);
		$readOnly->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$readOnly->method('getPath')->willReturn('/owner/files/Share/A.pad');
		$readOnly->method('isUpdateable')->willReturn(false);
		$writable = $this->padFile('A.pad', 42);
		$writable->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$writable->method('getPath')->willReturn('/owner/files/Share/A.pad');
		$writable->method('isUpdateable')->willReturn(true);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$readOnly, $writable]);
		$folder->method('getRelativePath')->willReturn('/A.pad');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($writable, $resolved->node);
	}

	public function testResolvePadFileRejectsAnIdThatIsNotTheSharedFile(): void {
		$share = $this->share($this->padFile('Shared.pad', 42), Constants::PERMISSION_READ);

		$this->expectException(ShareFileNotInShareException::class);
		$this->buildResolver()->resolvePadFile($share, '', 'token', 43);
	}

	/** Scoped to the folder, so it finds nothing - and nothing happens next. */
	public function testResolvePadFileRejectsAnIdOutsideTheShare(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(99)->willReturn([]);
		$folder->expects($this->never())->method('get');

		$this->expectException(ShareFileNotInShareException::class);
		$this->expectExceptionMessage('The selected file is not part of this share.');
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'Shared.pad', 'token', 99);
	}

	/** Belt to that brace, for the day getById()'s scoping stops holding. */
	public function testResolvePadFileRejectsAMatchThatIsNotBelowTheShare(): void {
		$outside = $this->padFile('Elsewhere.pad', 99);
		$outside->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$outside->method('getPath')->willReturn('/owner/files/Private/Elsewhere.pad');

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(99)->willReturn([$outside]);
		$folder->method('getRelativePath')->willReturn(null);

		$this->expectException(ShareFileNotInShareException::class);
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 99);
	}

	public function testResolvePadFileRefusesASubfolderIdAgainstARootPathOfTheSameName(): void {
		$folder = $this->folderResolving($this->padFile('A.pad', 42), 'Sub/A.pad');

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('The file id and the file path name different files.');
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'A.pad', 'token', 42);
	}

	public function testResolvePadFileAcceptsASubfolderIdWithItsFullRelativePath(): void {
		$file = $this->padFile('A.pad', 42);
		$folder = $this->folderResolving($file, 'Sub/A.pad');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'Sub/A.pad', 'token', 42);

		$this->assertSame($file, $resolved->node);
	}

	/** getById() may answer several times for one id, and only some are readable. */
	public function testResolvePadFileSkipsUnreadableAndNonFileMatches(): void {
		$unreadable = $this->padFile('A.pad', 42);
		$unreadable->method('getPermissions')->willReturn(0);
		$unreadable->method('getPath')->willReturn('/owner/files/Folder/A.pad');
		$readable = $this->padFile('A.pad', 42);
		$readable->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$readable->method('getPath')->willReturn('/owner/files/Folder/A.pad');

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->createMock(Folder::class), $unreadable, $readable]);
		$folder->method('getRelativePath')->willReturn('/A.pad');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($readable, $resolved->node);
	}

	/** Neither is opened: preferring one is the shape this exists to remove. */
	public function testResolvePadFileRefusesWhenTheIdAndThePathNameDifferentFiles(): void {
		$folder = $this->folderResolving($this->padFile('A.pad', 42), 'A.pad');

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('The file id and the file path name different files.');
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'B.pad', 'token', 42);
	}

	public function testResolvePadFileAcceptsAnIdAndPathThatAgree(): void {
		$file = $this->padFile('A.pad', 42);
		$folder = $this->folderResolving($file, 'A.pad');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'A.pad', 'token', 42);

		$this->assertSame($file, $resolved->node);
	}

	public static function unusableFileIdProvider(): array {
		return [
			'not a number' => ['abc'],
			'zero' => ['0'],
			'negative' => ['-1'],
			'float' => ['4.2'],
			'leading plus' => ['+42'],
			// Sent and empty is not the same as not sent: the controller
			// gives null for a parameter nobody passed.
			'empty' => [''],
			// `$` in a pattern matches before a trailing newline; ctype_digit
			// does not, which is why this one belongs here.
			'trailing newline' => ["42\n"],
			'array' => [['42']],
		];
	}

	/** Ignoring it would open the path instead - the fallback this avoids. */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusableFileIdProvider')]
	public function testResolvePadFileRejectsAnUnusableFileId(mixed $fileId): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('get');
		$folder->expects($this->never())->method('getById');

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('Invalid file id.');
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'A.pad', 'token', $fileId);
	}

	/** No id at all keeps the path mechanism, so existing links still open. */
	public function testResolvePadFileStillResolvesByPathWithoutAnId(): void {
		$file = $this->padFile('Shared.pad', 42);
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('get')->with('Shared.pad')->willReturn($file);
		$folder->expects($this->never())->method('getById');

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), 'Shared.pad', 'token', null);

		$this->assertSame($file, $resolved->node);
	}

	/** A mount carries a name of its own, so one id can have two names. */
	public function testResolvePadFilePrefersAPadOverANonPadNameForTheSameId(): void {
		$alias = $this->padFile('Alias.txt', 42);
		$alias->method('getPermissions')->willReturn(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$alias->method('getPath')->willReturn('/owner/files/Share/Alias.txt');
		$alias->method('isUpdateable')->willReturn(true);
		$pad = $this->padFile('A.pad', 42);
		$pad->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$pad->method('getPath')->willReturn('/owner/files/Share/Sub/A.pad');
		$pad->method('isUpdateable')->willReturn(false);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$alias, $pad]);
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => str_replace('/owner/files/Share', '', $path)
		);

		$resolved = $this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);

		$this->assertSame($pad, $resolved->node);
	}

	/** With no pad behind the id, the refusal still names the real reason. */
	public function testResolvePadFileRefusesAnIdThatOnlyNamesANonPad(): void {
		$folder = $this->folderResolving($this->padFile('Text.txt', 42), 'Text.txt');

		$this->expectException(NotAPadFileException::class);
		$this->expectExceptionMessage('The selected file is not a .pad document.');
		$this->buildResolver()->resolvePadFile($this->share($folder, Constants::PERMISSION_READ), '', 'token', 42);
	}

	public static function vanishingNodeCallProvider(): array {
		return [
			'getId' => ['getId', new NotFoundException('mount gone')],
			'isUpdateable' => ['isUpdateable', new InvalidPathException('bad path')],
		];
	}

	/** The mount can go away between finding the file and reading what it is. */
	#[\PHPUnit\Framework\Attributes\DataProvider('vanishingNodeCallProvider')]
	public function testResolvePadFileMapsANodeThatStopsAnsweringAfterSelection(string $method, \Throwable $thrown): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method($method)->willThrowException($thrown);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($file);
		$share = $this->share($folder, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$this->expectException(ShareItemUnavailableException::class);
		$this->expectExceptionMessage('This shared item is no longer available.');
		$this->buildResolver()->resolvePadFile($share, 'Shared.pad', 'token');
	}

	/** Same reading, from the branch that checks the id of a file share. */
	public function testResolvePadFileMapsAFileShareThatStopsAnsweringForItsId(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');
		$file->method('getId')->willThrowException(new NotFoundException('mount gone'));

		$this->expectException(ShareItemUnavailableException::class);
		$this->expectExceptionMessage('This shared item is no longer available.');
		$this->buildResolver()->resolvePadFile($this->share($file, Constants::PERMISSION_READ), '', 'token', 42);
	}

	private function buildResolver(?IManager $manager = null): PublicShareResolver {
		return new PublicShareResolver($manager ?? $this->createMock(IManager::class), new PathNormalizer());
	}

	/** A folder share whose getById() answers with one readable file. */
	private function folderResolving(File $file, string $relativePath): Folder {
		$file->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$file->method('getPath')->willReturn('/owner/files/Share/' . ltrim($relativePath, '/'));

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$folder->method('getRelativePath')->willReturn('/' . ltrim($relativePath, '/'));

		return $folder;
	}

	private function padFile(string $name, int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	private function share(File|Folder $node, int $permissions): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getNode')->willReturn($node);
		return $share;
	}
}
