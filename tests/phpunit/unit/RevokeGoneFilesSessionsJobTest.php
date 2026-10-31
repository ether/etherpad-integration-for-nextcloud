<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Whether one run was enough: back with only what is left, after a minute or backed off, and then given up. */
class RevokeGoneFilesSessionsJobTest extends TestCase {
	private const NOW = 1_000_000;

	public function testEndsWithNothingLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->start(['left' => [], 'failed' => false], $jobList, ['fileIds' => [1, 2]], [1, 2]);
	}

	public function testComesBackAfterAMinuteWithWhatIsLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['fileIds' => [2]]);

		$this->start(['left' => [2], 'failed' => false], $jobList, ['fileIds' => [1, 2]], [1, 2]);
	}

	/** @return iterable<string, array{array<string,mixed>, int, int}> */
	public static function backoff(): iterable {
		yield 'the first, naming no attempt yet' => [[], 60, 1];
		yield 'the second' => [['attempt' => 1], 300, 2];
		yield 'the third' => [['attempt' => 2], 900, 3];
	}

	/** @param array<string,mixed> $attempt */
	#[\PHPUnit\Framework\Attributes\DataProvider('backoff')]
	public function testBacksOffAFailureWithoutProgress(array $attempt, int $delay, int $next): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + $delay, ['fileIds' => [1, 2], 'attempt' => $next]);

		$this->start(['left' => [1, 2], 'failed' => true], $jobList, ['fileIds' => [1, 2]] + $attempt, [1, 2]);
	}

	/** A run its budget ended before it got further backs off too, or the next would do the same, without end. */
	public function testARunThatGotNowhereBacksOff(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['fileIds' => [1], 'attempt' => 1]);

		$this->start(['left' => [1], 'failed' => false], $jobList, ['fileIds' => [1]], [1]);
	}

	public function testProgressStartsTheBackoffOver(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['fileIds' => [2], 'attempt' => 1]);

		$this->start(['left' => [2], 'failed' => true], $jobList, ['fileIds' => [1, 2], 'attempt' => 2], [1, 2]);
	}

	public function testGivesUpAfterThreeRetriesWithoutProgress(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Gave up'), ['app' => 'etherpad_nextcloud', 'files' => 2]);

		$this->start(['left' => [1, 2], 'failed' => true], $jobList, ['fileIds' => [1, 2], 'attempt' => 3], [1, 2], $logger);
	}

	public function testANextRunThatCannotBeQueuedSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not queue'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['files'] === 1 && isset($context['error'])));

		$this->start(['left' => [2], 'failed' => false], $jobList, ['fileIds' => [1, 2]], [1, 2], $logger);
	}

	/** @return iterable<string, array{mixed}> */
	public static function notArguments(): iterable {
		yield 'not an array' => ['10'];
		yield 'no files' => [['attempt' => 1]];
		yield 'an empty list' => [['fileIds' => []]];
		yield 'no file id among them' => [['fileIds' => [0, -3, 'x']]];
	}

	/** @param mixed $argument */
	#[\PHPUnit\Framework\Attributes\DataProvider('notArguments')]
	public function testAnArgumentNamingNoFileDoesNothing($argument): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->never())->method('pass');

		$this->run_($revoker, $this->createMock(IJobList::class), $argument);
	}

	/** Stored job arguments may be malformed: text counts as the number it says, what is no file id is left out, a negative attempt as none. */
	public function testReadsAMalformedArgument(): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with([7, 9])->willReturn(['left' => [7, 9], 'failed' => true]);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['fileIds' => [7, 9], 'attempt' => 1]);

		$this->run_($revoker, $jobList, ['fileIds' => ['7', 0, 9, -2], 'attempt' => -4]);
	}

	/**
	 * @param array{left:list<int>,failed:bool} $pass
	 * @param array<string,mixed> $argument
	 * @param list<int> $fileIds
	 */
	private function start(array $pass, IJobList $jobList, array $argument, array $fileIds, ?LoggerInterface $logger = null): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with($fileIds)->willReturn($pass);
		$this->run_($revoker, $jobList, $argument, $logger);
	}

	/** @param mixed $argument */
	private function run_(GoneFileSessionRevoker $revoker, IJobList $jobList, $argument, ?LoggerInterface $logger = null): void {
		$job = new RevokeGoneFilesSessionsJob(new FixedClock(self::NOW), $revoker, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
	}
}
