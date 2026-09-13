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
use OCP\IAppConfig;
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
	 * Letting the failure through would cost the indexing run the rest of its
	 * files, so it is logged and the pad is left without content. Whatever
	 * finishes the write that held the lock marks the file for indexing again.
	 */
	#[DataProvider('recoverableFileReadErrors')]
	public function testLogsAPadItCannotReadAndIndexesNoContent(\Throwable $error): void {
		$file = $this->file('Notes.pad', 'unused');
		$file->method('getContent')->willThrowException($error);
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())->method('setContent')->with('', IIndexDocument::NOT_ENCODED);

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

	public static function contentIndexingDecisions(): array {
		return [
			'local files, on by default' => ['files_local', [], true],
			'local files switched off' => ['files_local', ['files_local' => false], false],
			'external storage, off by default' => ['files_external', [], false],
			'external storage switched on' => ['files_external', ['files_external' => 1], true],
			'team folders, off by default' => ['files_group_folders', [], false],
			'team folders switched on' => ['files_group_folders', ['files_group_folders' => true], true],
			'a storage nobody claimed' => ['', [], false],
		];
	}

	/**
	 * Files FullTextSearch asks per storage whether file content may be
	 * indexed, but every extractor it has returns on our MIME type before it
	 * gets that far - so the answer has to be honoured here instead.
	 *
	 * @param array<string, bool|int> $settings
	 */
	#[DataProvider('contentIndexingDecisions')]
	public function testIndexesContentOnlyWhereTheAdminAllowedIt(string $source, array $settings, bool $indexed): void {
		$service = new PadFileService(new FixedClock());
		$file = $this->file(
			'Meeting.pad',
			$service->buildInitialDocument(
				42,
				'demo-pad',
				BindingService::ACCESS_PUBLIC,
				new PadSnapshot('searchable words', '', 3),
			),
		);
		$document = $this->createMock(IIndexDocument::class);
		$document->expects(self::once())
			->method('setContent')
			->with($indexed ? 'searchable words' : '', IIndexDocument::NOT_ENCODED);

		$this->listener($service, appConfig: $this->appConfig($settings))
			->handle($this->indexingEvent($file, $document, $source));
	}

	private function listener(
		?PadFileService $service = null,
		?LoggerInterface $logger = null,
		?IAppConfig $appConfig = null,
	): FullTextSearchIndexingListener {
		return new FullTextSearchIndexingListener(
			$service ?? new PadFileService(new FixedClock()),
			$appConfig ?? $this->appConfig(),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @param array<string, bool|int> $values keyed by the files_fulltextsearch setting
	 */
	private function appConfig(array $values = []): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')
			->willReturnCallback(
				fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => (bool)($values[$key] ?? $default)
			);
		$appConfig->method('getValueInt')
			->willReturnCallback(
				fn (string $app, string $key, int $default = 0, bool $lazy = false): int => (int)($values[$key] ?? $default)
			);
		return $appConfig;
	}

	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}

	private function indexingEvent(File $file, IIndexDocument $document, string $source = 'files_local'): GenericEvent {
		$document->method('getSource')->willReturn($source);
		return new GenericEvent('Files_FullTextSearch.onFileIndexing', [
			'file' => $file,
			'document' => $document,
		]);
	}
}
