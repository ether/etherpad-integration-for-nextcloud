<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeFolderSessionsJob;
use OCA\EtherpadNextcloud\Service\FolderSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Whether one run was enough: back after a minute with the rows left, backed off after a failure, and then given up. */
class RevokeFolderSessionsJobTest extends TestCase {
	private const NOW = 1_000_000;

	public function testEndsWhenThePassEnds(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->start(['after' => 9, 'ended' => true, 'failed' => false], $jobList, ['folderId' => 10], 0);
	}

	public function testComesBackAfterAMinuteForTheRowsLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + 60, ['folderId' => 10, 'after' => 500]);

		$this->start(['after' => 500, 'ended' => false, 'failed' => false], $jobList, ['folderId' => 10, 'after' => 0], 0);
	}

	/** @return iterable<string, array{int, int}> */
	public static function backoff(): iterable {
		yield 'the first' => [0, 60];
		yield 'the second' => [1, 300];
		yield 'the third' => [2, 900];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('backoff')]
	public function testBacksOffAfterAFailure(int $attempt, int $delay): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + $delay, ['folderId' => 10, 'after' => 7, 'attempt' => $attempt + 1]);

		$this->start(['after' => 7, 'ended' => false, 'failed' => true], $jobList, ['folderId' => 10, 'after' => 7, 'attempt' => $attempt], 7);
	}

	/** Stored as text, the folder and the row still name what they name. */
	public function testAnArgumentAsTextCounts(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + 60, ['folderId' => 10, 'after' => 8]);

		$this->start(['after' => 8, 'ended' => false, 'failed' => false], $jobList, ['folderId' => '10', 'after' => '7'], 7);
	}

	public function testProgressStartsTheBackoffOver(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + 60, ['folderId' => 10, 'after' => 8, 'attempt' => 1]);

		$this->start(['after' => 8, 'ended' => false, 'failed' => true], $jobList, ['folderId' => 10, 'after' => 7, 'attempt' => 2], 7);
	}

	/** A run its budget ended before it got further: back off, or the next would do the same at once, without end. */
	public function testARunThatGotNowhereBacksOff(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + 300, ['folderId' => 10, 'after' => 7, 'attempt' => 2]);

		$this->start(['after' => 7, 'ended' => false, 'failed' => false], $jobList, ['folderId' => 10, 'after' => 7, 'attempt' => 1], 7);
	}

	public function testGivesUpAfterThreeRetriesWithoutProgress(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Gave up'), ['app' => 'etherpad_nextcloud', 'fileId' => 10]);

		$this->start(['after' => 7, 'ended' => false, 'failed' => true], $jobList, ['folderId' => 10, 'after' => 7, 'attempt' => 3], 7, $logger);
	}

	public function testANextRunThatCannotBeQueuedSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not queue'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['fileId'] === 10 && isset($context['error'])));

		$this->start(['after' => 500, 'ended' => false, 'failed' => false], $jobList, ['folderId' => 10], 0, $logger);
	}

	/** @return iterable<string, array{mixed}> */
	public static function notArguments(): iterable {
		yield 'not an array' => ['10'];
		yield 'no folder' => [['after' => 3]];
		yield 'no positive folder' => [['folderId' => 0]];
	}

	/** @param mixed $argument */
	#[\PHPUnit\Framework\Attributes\DataProvider('notArguments')]
	public function testAnArgumentNamingNoFolderDoesNothing($argument): void {
		$revoker = $this->createMock(FolderSessionRevoker::class);
		$revoker->expects($this->never())->method('pass');

		$this->pass($revoker, $this->createMock(IJobList::class), $argument);
	}

	/** A row is just data: a negative attempt or cursor counts as none. */
	public function testANegativeAttemptOrCursorCountsAsNone(): void {
		$revoker = $this->createMock(FolderSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with(10, 0)->willReturn(['after' => 0, 'ended' => false, 'failed' => true]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeFolderSessionsJob::class, self::NOW + 60, ['folderId' => 10, 'after' => 0, 'attempt' => 1]);

		$this->pass($revoker, $jobList, ['folderId' => 10, 'after' => -4, 'attempt' => -2]);
	}

	/**
	 * @param array{after:int,ended:bool,failed:bool} $pass
	 * @param array<string,int|string> $argument
	 */
	private function start(array $pass, IJobList $jobList, array $argument, int $after, ?LoggerInterface $logger = null): void {
		$revoker = $this->createMock(FolderSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with(10, $after)->willReturn($pass);
		$this->pass($revoker, $jobList, $argument, $logger);
	}

	/** @param mixed $argument */
	private function pass(FolderSessionRevoker $revoker, IJobList $jobList, $argument, ?LoggerInterface $logger = null): void {
		$job = new RevokeFolderSessionsJob(new FixedClock(self::NOW), $revoker, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
	}
}
