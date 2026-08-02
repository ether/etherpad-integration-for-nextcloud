<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\AdminValidationException;
use OCA\EtherpadNextcloud\Exception\TemplateExistsException;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadTemplateAdminService;
use OCA\EtherpadNextcloud\Service\PadTemplateStorage;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCP\Files\File;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidPathException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PadTemplateAdminServiceTest extends TestCase {
	private const PAD = "---\nformat: \"etherpad-nextcloud/1\"\npad_id: \"nc-abc\"\n---\n";

	public function testListsWhatIsStored(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('globalTemplates')->willReturn([$this->file('Meeting notes.pad', 314, 1_700_000_000)]);

		$this->assertSame(
			[['name' => 'Meeting notes.pad', 'size' => 314, 'modified' => 1_700_000_000]],
			$this->buildService($storage)->list(),
		);
	}

	public function testStoresAValidTemplate(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->once())
			->method('addGlobalTemplate')
			->with('Meeting notes.pad', self::PAD, false)
			->willReturn($this->file('Meeting notes.pad', 10, 1));

		$stored = $this->buildService($storage)->add('  Meeting notes.pad  ', self::PAD);

		$this->assertSame('Meeting notes.pad', $stored['name']);
	}

	/**
	 * Rejecting here rather than at pad creation is the point: by the time a
	 * user picks a broken tile, no admin is watching.
	 *
	 * @return iterable<string,array{0:string,1:string}>
	 */
	public static function rejectedProvider(): iterable {
		yield 'no name' => ['', self::PAD];
		yield 'not a pad file' => ['notes.txt', self::PAD];
		yield 'name carries a path' => ['../secrets.pad', self::PAD];
		yield 'hidden file' => ['.secrets.pad', self::PAD];
		yield 'empty content' => ['notes.pad', "   \n"];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('rejectedProvider')]
	public function testRejectsUnusableTemplates(string $name, string $content): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->never())->method('addGlobalTemplate');

		$this->expectException(AdminValidationException::class);
		$this->buildService($storage)->add($name, $content);
	}

	public function testRejectsAPadFromAnotherServer(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->never())->method('addGlobalTemplate');

		$this->expectException(AdminValidationException::class);
		$this->buildService($storage, 'ext.remote-1', true)->add('imported.pad', self::PAD);
	}

	public function testRejectsAnOversizedFile(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->never())->method('addGlobalTemplate');

		$this->expectException(AdminValidationException::class);
		$this->buildService($storage)->add('big.pad', str_repeat('x', 3 * 1024 * 1024));
	}

	/**
	 * The parser throws on damaged frontmatter, which the admin mapper would
	 * otherwise answer with a 500. Uses the real PadFileService: a mock would
	 * never take that path.
	 */
	public function testReportsUnparseableContentAsAValidationError(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->never())->method('addGlobalTemplate');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new PadTemplateAdminService($storage, new PadFileService(), $this->filenameValidator(), $l10n);

		$this->expectException(AdminValidationException::class);
		$service->add('broken.pad', "not a pad file at all\njust text\n");
	}

	/**
	 * What an instance forbids beyond our own rules is Nextcloud's business —
	 * asked here so the admin gets a sentence instead of a 500 from deep in
	 * the storage.
	 */
	public function testRejectsWhatNextcloudRejects(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->never())->method('addGlobalTemplate');

		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('validateFilename')->willThrowException(new InvalidPathException('Filename contains at least one invalid character'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new PadTemplateAdminService($storage, new PadFileService(), $validator, $l10n);

		$this->expectExceptionMessage('Filename contains at least one invalid character');
		$service->add('note*s.pad', self::PAD);
	}

	/**
	 * There is no versioning or trash behind that folder, so a mistaken file
	 * would destroy the previous template without a trace.
	 */
	public function testRefusesToOverwriteUnlessAsked(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('addGlobalTemplate')->willThrowException(new TemplateExistsException('Meeting notes.pad'));

		try {
			$this->buildService($storage)->add('Meeting notes.pad', self::PAD);
			$this->fail('Expected a validation error');
		} catch (AdminValidationException $e) {
			// Its own field, so the page can tell this apart from a bad file
			// and offer to replace instead of just reporting a failure.
			$this->assertSame('template_exists', $e->getField());
		}
	}

	public function testPassesTheReplaceDecisionToTheStorage(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		// The storage decides it while writing; splitting the question off into
		// a check here would reopen the race it exists to close.
		$storage->expects($this->once())->method('addGlobalTemplate')
			->with('Meeting notes.pad', self::PAD, true)
			->willReturn($this->file('Meeting notes.pad', 2, 2));

		$this->buildService($storage)->add('Meeting notes.pad', self::PAD, true);
	}

	public function testDeletesByName(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->expects($this->once())->method('deleteGlobalTemplate')->with('gone.pad')->willReturn(true);

		$this->buildService($storage)->delete('gone.pad');
	}

	public function testReportsADeleteOfSomethingThatIsNotThere(): void {
		$storage = $this->createMock(PadTemplateStorage::class);
		$storage->method('deleteGlobalTemplate')->willReturn(false);

		$this->expectException(AdminValidationException::class);
		$this->buildService($storage)->delete('gone.pad');
	}

	/** Accepts everything by default; the rules themselves are Nextcloud's. */
	private function filenameValidator(): IFilenameValidator {
		return $this->createMock(IFilenameValidator::class);
	}

	private function file(string $name, int $size, int $mtime): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn($mtime);
		return $file;
	}

	private function buildService(
		PadTemplateStorage $storage,
		string $padId = 'nc-abc',
		bool $isExternal = false,
	): PadTemplateAdminService {
		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('readPad')->willReturn(new ParsedPadFile(
			frontmatter: [],
			body: '',
			padId: $padId,
			accessMode: 'protected',
			padUrl: '',
			isExternal: $isExternal,
		));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PadTemplateAdminService($storage, $padFileService, $this->filenameValidator(), $l10n);
	}
}
