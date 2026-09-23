<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\PadSnapshot;
use OCA\EtherpadNextcloud\Service\ParsedPadFile;
use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Service\TrashSnapshotMiss;
use OCA\EtherpadNextcloud\Service\TrashSnapshotWriter;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\Files\File;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrashSnapshotWriterTest extends TestCase {
	private EtherpadClient&MockObject $etherpad;
	private PadFileService&MockObject $padFiles;
	private LoggerInterface&MockObject $logger;
	private File&MockObject $file;
	/** @var list<array{string,string}> level and reason of each line logged */
	private array $logged;
	/** @var list<PadSnapshot> what went into the file */
	private array $written;
	private ?string $fault;

	protected function setUp(): void {
		$this->logged = [];
		$this->written = [];
		$this->fault = null;
		$this->etherpad = $this->createMock(EtherpadClient::class);
		$this->padFiles = $this->createMock(PadFileService::class);
		$this->padFiles->method('withExportSnapshot')->willReturnCallback(function (ParsedPadFile $pad, PadSnapshot $snapshot): string {
			$this->written[] = $snapshot;
			return 'doc-after';
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$this->logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->assertSame('A trashed .pad file did not get its snapshot. Its pad is kept for now.', $message);
				$this->assertSame(7, $context['fileId']);
				$this->logged[] = [$level, $context['reason']];
			});
		}
		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(7);
	}

	/**
	 * Every way the file itself keeps a snapshot out, as one reason each.
	 * What needs a look is a warning while it is news; a lock and an empty
	 * file are debug either way.
	 */
	public function testReadSaysWhyThereIsNothingToWriteInto(): void {
		$cases = [
			'locked' => [new LockedException('x.pad'), TrashSnapshotMiss::FileLocked, 'debug'],
			'unreadable' => [new \RuntimeException('no key for this user'), TrashSnapshotMiss::FileUnreadable, 'warning'],
			'empty' => ['', TrashSnapshotMiss::FileEmpty, 'debug'],
			'unparsable' => ['no frontmatter', TrashSnapshotMiss::FileUnparsable, 'warning'],
		];
		foreach ($cases as $case => [$content, $miss, $level]) {
			foreach ([true => $level, false => 'debug'] as $news => $expected) {
				$this->setUp();
				if ($content instanceof \Throwable) {
					$this->file->method('getContent')->willThrowException($content);
				} else {
					$this->file->method('getContent')->willReturn($content);
				}
				$this->padFiles->method('readPad')->willThrowException(new \RuntimeException('Missing YAML frontmatter'));

				$this->assertSame($miss, $this->writer(news: (bool)$news)->read(), $case);
				$this->assertSame([[$expected, $miss->value]], $this->logged, $case . ($news ? ', news' : ', a repeat'));
			}
		}
	}

	public function testReadHandsBackThePadAsTheFileRecordsIt(): void {
		$this->file->method('getContent')->willReturn('doc-before');
		$pad = $this->pad(snapshotRev: 3);
		$this->padFiles->method('readPad')->with('doc-before')->willReturn($pad);

		$this->assertSame($pad, $this->writer()->read());
		$this->assertSame([], $this->logged);
	}

	/** The injected read lock of a debug instance reads as the lock of a WebDAV delete. */
	public function testTheReadLockFaultReadsAsALock(): void {
		$this->file->expects($this->never())->method('getContent');
		$this->fault = TrashSnapshotWriter::FAULT_READ_LOCK;

		$this->assertSame(TrashSnapshotMiss::FileLocked, $this->writer()->read());
	}

	/**
	 * Held to the file's snapshot revision: a file that has it already needs
	 * nothing, a pad behind it is not the file's, and a count that moves
	 * while the text is read makes the text belong to neither. Nothing is
	 * written in any of them.
	 */
	public function testTheSweepGoesByTheRevisionTheFileHas(): void {
		$this->file->expects($this->never())->method('putContent');
		$this->etherpad->expects($this->never())->method('getRevisionsCount');
		$this->etherpad->expects($this->never())->method('getText');
		$this->assertTrue($this->writer()->writeInTrash($this->pad(snapshotRev: 4), 4, $this->notMoved()), 'held already');

		$this->setUp();
		$this->file->expects($this->never())->method('putContent');
		$this->etherpad->method('getRevisionsCount')->willReturn(4);
		$this->assertTrue($this->writer()->writeInTrash($this->pad(snapshotRev: 4), null, $this->notMoved()), 'counted here');

		$this->setUp();
		$this->file->expects($this->never())->method('putContent');
		$this->etherpad->expects($this->never())->method('getText');
		$this->assertSame(TrashSnapshotMiss::PadBehind, $this->writer()->writeInTrash($this->pad(snapshotRev: 4), 2, $this->notMoved()));
		$this->assertSame([['debug', 'pad_behind']], $this->logged);

		$this->setUp();
		$this->file->expects($this->never())->method('putContent');
		$this->etherpad->method('getRevisionsCount')->willReturn(6);
		$this->etherpad->method('getText')->willReturn('text');
		$this->etherpad->method('getHTML')->willReturn('<p>text</p>');
		$this->assertSame(TrashSnapshotMiss::PadChanged, $this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved()));
		$this->assertSame([['debug', 'pad_changed']], $this->logged);
	}

	/**
	 * The text between two counts, the question whether the file moved
	 * right before the write, and one count more after it. The count the
	 * caller has just taken is the first.
	 */
	public function testTheSweepWritesWhatItReadBetweenTwoCounts(): void {
		$steps = [];
		$this->etherpad->method('getText')->with('pad-a')->willReturnCallback(static function () use (&$steps): string {
			$steps[] = 'text';
			return 'text';
		});
		$this->etherpad->method('getHTML')->with('pad-a')->willReturnCallback(static function () use (&$steps): string {
			$steps[] = 'html';
			return '<p>text</p>';
		});
		$this->etherpad->method('getRevisionsCount')->with('pad-a')->willReturnCallback(static function () use (&$steps): int {
			$steps[] = 'count';
			return 5;
		});
		$this->file->method('putContent')->with('doc-after')->willReturnCallback(static function () use (&$steps): void {
			$steps[] = 'write';
		});
		$moved = static function () use (&$steps): bool {
			$steps[] = 'moved?';
			return false;
		};

		$this->assertTrue($this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, $moved));

		$this->assertSame(['text', 'html', 'count', 'moved?', 'write', 'count'], $steps);
		$this->assertEquals([new PadSnapshot('text', '<p>text</p>', 5)], $this->written);
		$this->assertSame([], $this->logged);
	}

	/** A restore took the file back while its snapshot was read: a write through the old node would make a new file. */
	public function testAFileRestoredMeanwhileIsNotWrittenTo(): void {
		$this->etherpad->method('getRevisionsCount')->willReturn(5);
		$this->etherpad->method('getText')->willReturn('text');
		$this->etherpad->method('getHTML')->willReturn('<p>text</p>');
		$this->file->expects($this->never())->method('putContent');

		$this->assertSame(TrashSnapshotMiss::FileMoved, $this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, static fn (): bool => true));
		$this->assertSame([['debug', 'file_moved']], $this->logged);
	}

	/**
	 * Each call gets what is left of the run, and one that would not finish
	 * is not made: the spent budget is the caller's to place.
	 */
	public function testEachCallGetsWhatIsLeftOfTheRun(): void {
		$clock = new FixedClock();
		$timeouts = [];
		$this->etherpad->method('getText')->willReturnCallback(static function (string $padId, ?int $timeout) use ($clock, &$timeouts): string {
			$timeouts[] = $timeout;
			$clock->advance(4);
			return 'text';
		});
		$this->etherpad->method('getHTML')->willReturnCallback(static function (string $padId, ?int $timeout) use ($clock, &$timeouts): string {
			$timeouts[] = $timeout;
			$clock->advance(15);
			return '<p>text</p>';
		});
		$this->etherpad->expects($this->never())->method('getRevisionsCount');

		try {
			$this->writer(budget: new RunBudget($clock, 20.0))->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved());
			$this->fail('A call that could not finish in the run was made.');
		} catch (RunBudgetSpentException) {
		}
		$this->assertSame([15, 15], $timeouts);
		$this->assertSame([], $this->logged);
	}

	/**
	 * Written, then counted once more: a pad that moved on while the file
	 * was written stays for another snapshot.
	 */
	public function testAPadThatMovedOnDuringTheWriteStays(): void {
		foreach ([5 => true, 6 => TrashSnapshotMiss::PadChanged] as $recount => $expected) {
			$this->setUp();
			$this->file->expects($this->once())->method('putContent')->with('doc-after');
			$this->etherpad->method('getText')->willReturn('text');
			$this->etherpad->method('getHTML')->willReturn('');
			$this->etherpad->method('getRevisionsCount')->willReturnOnConsecutiveCalls(5, $recount);

			$this->assertSame($expected, $this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved()), "recount $recount");
		}
	}

	/** A write that fails, for real or by an injected fault, is not counted again and says why. */
	public function testAWriteThatFailsSaysWhy(): void {
		$cases = [
			'locked' => [new LockedException('x.pad'), null, TrashSnapshotMiss::FileLocked, 'debug'],
			'refused' => [new \RuntimeException('disk full'), null, TrashSnapshotMiss::WriteFailed, 'warning'],
			'lock fault' => [null, TrashSnapshotWriter::FAULT_WRITE_LOCK, TrashSnapshotMiss::FileLocked, 'debug'],
			'fail fault' => [null, TrashSnapshotWriter::FAULT_WRITE_FAIL, TrashSnapshotMiss::WriteFailed, 'warning'],
		];
		foreach ($cases as $case => [$error, $fault, $miss, $level]) {
			$this->setUp();
			$this->fault = $fault;
			if ($error !== null) {
				$this->file->method('putContent')->willThrowException($error);
			} else {
				$this->file->expects($this->never())->method('putContent');
			}
			$this->etherpad->method('getText')->willReturn('text');
			$this->etherpad->method('getHTML')->willReturn('');
			// After the text only.
			$this->etherpad->expects($this->once())->method('getRevisionsCount')->willReturn(5);

			$this->assertSame($miss, $this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved()), $case);
			$this->assertSame([[$level, $miss->value]], $this->logged, $case);
		}
	}

	/**
	 * Etherpad giving no answer is a miss of its own, reported each time
	 * however often the file was tried before. Any other error is not the
	 * writer's to place.
	 */
	public function testEtherpadSilenceInTheSweepIsAMissReportedEachTime(): void {
		$this->etherpad->method('getText')->willThrowException(new EtherpadClientException('Operation timed out'));
		$this->file->expects($this->never())->method('putContent');

		$this->assertSame(TrashSnapshotMiss::SnapshotNotFetched, $this->writer(news: false)->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved()));
		$this->assertSame([['warning', 'snapshot_not_fetched']], $this->logged);

		$this->setUp();
		$this->etherpad->method('getText')->willThrowException(new \LogicException('a bug'));
		try {
			$this->writer()->writeInTrash($this->pad(snapshotRev: 4), 5, $this->notMoved());
			$this->fail('An error that is not Etherpad\'s was placed as its silence.');
		} catch (\LogicException) {
		}
		$this->assertSame([], $this->logged);
	}

	/**
	 * At trash time any error on the way is a snapshot not taken, so the
	 * user's delete goes through, and it is reported each time.
	 */
	public function testAtTrashAnErrorIsASnapshotNotTaken(): void {
		$this->etherpad->method('getRevisionsCount')->willThrowException(new \RuntimeException('Connection reset'));
		$this->file->expects($this->never())->method('putContent');

		$this->assertFalse($this->writer(news: false)->writeAtTrash($this->pad(snapshotRev: 4)));
		$this->assertSame([['warning', 'snapshot_not_fetched']], $this->logged);
	}

	/** A spent budget is the caller's, at trash time as in the sweep: not a snapshot Etherpad did not give. */
	public function testAtTrashASpentBudgetIsNotAnError(): void {
		$this->etherpad->expects($this->never())->method('getRevisionsCount');

		try {
			$this->writer(budget: new RunBudget(new FixedClock(), 1.0))->writeAtTrash($this->pad(snapshotRev: 4));
			$this->fail('A spent budget was taken for a snapshot not taken.');
		} catch (RunBudgetSpentException) {
		}
		$this->assertSame([], $this->logged);
	}

	public function testAtTrashTheFileHoldsThePadOnceWritten(): void {
		$this->etherpad->method('getRevisionsCount')->willReturn(5);
		$this->etherpad->method('getText')->willReturn('text');
		$this->etherpad->method('getHTML')->willReturn('<p>text</p>');
		$this->file->expects($this->once())->method('putContent')->with('doc-after');

		$this->assertTrue($this->writer()->writeAtTrash($this->pad(snapshotRev: 4)));
		$this->assertEquals([new PadSnapshot('text', '<p>text</p>', 5)], $this->written);
		$this->assertSame([], $this->logged);

		$this->setUp();
		$this->etherpad->method('getRevisionsCount')->willReturn(4);
		$this->file->expects($this->never())->method('putContent');
		$this->assertTrue($this->writer()->writeAtTrash($this->pad(snapshotRev: 4)), 'held already');
	}

	/** A miss at trash time is no snapshot either: the pad is not deleted on it. */
	public function testAtTrashAMissKeepsThePad(): void {
		$this->etherpad->method('getRevisionsCount')->willReturn(2);
		$this->file->expects($this->never())->method('putContent');

		$this->assertFalse($this->writer()->writeAtTrash($this->pad(snapshotRev: 4)));
		$this->assertSame([['debug', 'pad_behind']], $this->logged);

		$this->setUp();
		$this->etherpad->method('getRevisionsCount')->willReturnOnConsecutiveCalls(5, 5, 6);
		$this->etherpad->method('getText')->willReturn('text');
		$this->etherpad->method('getHTML')->willReturn('<p>text</p>');
		$this->file->expects($this->once())->method('putContent');

		$this->assertFalse($this->writer()->writeAtTrash($this->pad(snapshotRev: 4)), 'changed after the write');
		$this->assertSame([['debug', 'pad_changed']], $this->logged);
	}

	private function writer(bool $news = true, ?RunBudget $budget = null): TrashSnapshotWriter {
		$fault = &$this->fault;
		return new TrashSnapshotWriter(
			$this->etherpad,
			$this->padFiles,
			$this->logger,
			static function (string $candidate) use (&$fault): bool {
				return $candidate === $fault;
			},
			$this->file,
			'pad-a',
			$news,
			$budget,
		);
	}

	/** @return \Closure(): bool */
	private function notMoved(): \Closure {
		return static fn (): bool => false;
	}

	private function pad(int $snapshotRev): ParsedPadFile {
		return new ParsedPadFile(
			frontmatter: [],
			body: 'body',
			padId: 'pad-a',
			accessMode: BindingService::ACCESS_PUBLIC,
			padUrl: '',
			isExternal: false,
			snapshotRev: $snapshotRev,
		);
	}
}
