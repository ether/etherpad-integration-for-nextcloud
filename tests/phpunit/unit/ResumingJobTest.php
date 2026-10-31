<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\ResumingJob;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether one run was enough: nothing left ends it, a run that got further
 * comes back after a minute, one that failed or got nowhere backs off, and
 * three retries without progress end it with a warning.
 */
class ResumingJobTest extends TestCase {
	private const NOW = 1_000_000;

	public function testEndsWithNothingLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->start(null, $jobList, []);
	}

	public function testComesBackAfterAMinuteWhileItGetsFurther(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with($this->anything(), self::NOW + 60, ['left' => 1]);

		$this->start(self::pass(progress: true), $jobList, ['attempt' => 2]);
	}

	/** @return iterable<string, array{array<string,mixed>, bool, int, int}> */
	public static function backoff(): iterable {
		yield 'a failure, naming no attempt yet' => [[], true, 60, 1];
		yield 'a run that got nowhere' => [[], false, 60, 1];
		yield 'the second' => [['attempt' => 1], true, 300, 2];
		yield 'the third' => [['attempt' => 2], false, 900, 3];
		yield 'a negative attempt counts as none' => [['attempt' => -4], true, 60, 1];
	}

	/** @param array<string,mixed> $argument */
	#[\PHPUnit\Framework\Attributes\DataProvider('backoff')]
	public function testBacksOffWithoutProgress(array $argument, bool $failed, int $delay, int $attempt): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with($this->anything(), self::NOW + $delay, ['left' => 1, 'attempt' => $attempt]);

		$this->start(self::pass(failed: $failed), $jobList, $argument);
	}

	public function testProgressStartsTheBackoffOver(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with($this->anything(), self::NOW + 60, ['left' => 1, 'attempt' => 1]);

		$this->start(self::pass(progress: true, failed: true), $jobList, ['attempt' => 3]);
	}

	public function testGivesUpAfterThreeRetriesWithoutProgress(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('gave up', ['app' => 'etherpad_nextcloud', 'item' => 7]);

		$this->start(self::pass(failed: true), $jobList, ['attempt' => 3], $logger);
	}

	public function testANextRunThatCannotBeQueuedSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('lost', $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['item'] === 7 && isset($context['error'])));

		$this->start(self::pass(progress: true), $jobList, [], $logger);
	}

	/** What is no array reaches the job as none, for it to find nothing named. */
	public function testHandsOverAnArgumentThatIsNoArrayAsEmpty(): void {
		$job = $this->job(null, $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));
		$job->setArgument('10');

		$job->start($this->createMock(IJobList::class));

		$this->assertSame([[]], $job->seen);
	}

	/** @return array{next:array<string,mixed>,progress:bool,failed:bool,context:array<string,mixed>} */
	private static function pass(bool $progress = false, bool $failed = false): array {
		return ['next' => ['left' => 1], 'progress' => $progress, 'failed' => $failed, 'context' => ['item' => 7]];
	}

	/**
	 * @param ?array{next:array<string,mixed>,progress:bool,failed:bool,context:array<string,mixed>} $pass
	 * @param array<string,mixed> $argument
	 */
	private function start(?array $pass, IJobList $jobList, array $argument, ?LoggerInterface $logger = null): void {
		$job = $this->job($pass, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
	}

	/** @param ?array{next:array<string,mixed>,progress:bool,failed:bool,context:array<string,mixed>} $pass */
	private function job(?array $pass, IJobList $jobList, LoggerInterface $logger): ResumingJob {
		return new class($pass, new FixedClock(self::NOW), $jobList, $logger) extends ResumingJob {
			/** @var list<array<array-key,mixed>> */
			public array $seen = [];

			public function __construct(private ?array $scripted, ITimeFactory $time, IJobList $jobList, LoggerInterface $logger) {
				parent::__construct($time, $jobList, $logger);
			}

			protected function pass(array $argument): ?array {
				$this->seen[] = $argument;
				return $this->scripted;
			}

			protected function gaveUpMessage(): string {
				return 'gave up';
			}

			protected function lostRunMessage(): string {
				return 'lost';
			}
		};
	}
}
