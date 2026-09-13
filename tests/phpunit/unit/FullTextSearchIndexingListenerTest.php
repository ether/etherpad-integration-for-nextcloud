<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FullTextSearchIndexingListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FullTextSearchIndexingListenerTest extends TestCase {
	public function testIndexesOnlyTheStoredPlainTextSnapshot(): void {
		$service = new PadFileService(new FixedClock());
		$file = $this->file(
			'Meeting.pad',
			$service->buildInitialDocument(
				42,
				'demo-pad',
				BindingService::ACCESS_PUBLIC,
				new PadSnapshot("searchable words\nsecond line", '<p>HTML must not be indexed</p>', 3),
			),
		);
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())
			->method('setContent')
			->with("searchable words\nsecond line", IIndexDocument::NOT_ENCODED);

		$this->listener($service)->handle($this->indexingEvent($file, $document));
	}

	public function testMatchesPadNamesCaseInsensitively(): void {
		$service = new PadFileService(new FixedClock());
		$file = $this->file(
			'Notes.PAD',
			$service->buildInitialDocument(42, 'demo-pad', BindingService::ACCESS_PUBLIC),
		);
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())->method('setContent')->with('', IIndexDocument::NOT_ENCODED);

		$this->listener($service)->handle($this->indexingEvent($file, $document));
	}

	public function testIgnoresOtherFiles(): void {
		$file = $this->file('Notes.txt', 'plain text');
		$file->expects(self::never())->method('getContent');
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::never())->method('setContent');

		$this->listener()->handle($this->indexingEvent($file, $document));
	}

	public function testIgnoresOtherGenericEvents(): void {
		$file = $this->file('Notes.pad', 'not read');
		$file->expects(self::never())->method('getContent');
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::never())->method('setContent');

		$this->listener()->handle(new GenericEvent('Files_FullTextSearch.onSearchResult', [
			'file' => $file,
			'document' => $document,
		]));
	}

	public function testLeavesMalformedPadContentUnindexed(): void {
		$file = $this->file('Broken.pad', 'not a pad document');
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())->method('setContent')->with('', IIndexDocument::NOT_ENCODED);

		$this->listener()->handle($this->indexingEvent($file, $document));
	}

	public static function recoverableFileReadErrors(): array {
		return [
			'locked file' => [new LockedException('/Notes.pad')],
			'forbidden read' => [new NotPermittedException('not permitted')],
			'generic file failure' => [new GenericFileException('storage unavailable')],
			'deleted mid-run' => [new NotFoundException('gone')],
		];
	}

	/**
	 * Saying "empty" settles the document as indexed, and the pad then stays
	 * out of search until its own mtime changes. Leaving the field alone
	 * keeps it eligible for the next pass, which is what these failures are:
	 * a sync holding the lock, a file deleted while the run walked the list.
	 */
	#[DataProvider('recoverableFileReadErrors')]
	public function testLeavesTemporarilyUnreadablePadsForTheNextPass(\Throwable $error): void {
		$file = $this->file('Notes.pad', 'unused');
		$file->method('getContent')->willThrowException($error);
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::never())->method('setContent');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug');

		$this->listener(logger: $logger)->handle($this->indexingEvent($file, $document));
	}

	/**
	 * A file that is not a managed pad has no snapshot and will not have one
	 * until somebody opens it, so empty is the honest answer rather than a
	 * document left waiting for a read that will fail the same way.
	 */
	public function testRecordsAFileWithoutFrontmatterAsEmpty(): void {
		$file = $this->file('Notes.pad', 'no frontmatter here');
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())->method('setContent')->with('', IIndexDocument::NOT_ENCODED);

		$this->listener()->handle($this->indexingEvent($file, $document));
	}

	public function testLetsUnexpectedFailuresReachTheIndexer(): void {
		$file = $this->file('Notes.pad', 'unused');
		$file->method('getContent')->willThrowException(new \RuntimeException('unexpected failure'));

		$this->expectExceptionMessage('unexpected failure');
		$this->listener()->handle($this->indexingEvent($file, $this->createMock(IIndexDocument::class)));
	}

	private function listener(?PadFileService $service = null, ?LoggerInterface $logger = null): FullTextSearchIndexingListener {
		return new FullTextSearchIndexingListener(
			$service ?? new PadFileService(new FixedClock()),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}

	private function indexingEvent(File $file, IIndexDocument $document): GenericEvent {
		return new GenericEvent('Files_FullTextSearch.onFileIndexing', [
			'file' => $file,
			'document' => $document,
		]);
	}
}
