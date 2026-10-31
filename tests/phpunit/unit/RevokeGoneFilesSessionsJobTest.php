<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\RevokeGoneFilesSessionsJob;
use OCA\EtherpadNextcloud\Service\GoneFileSessionRevoker;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Batch arguments and log context; retry scheduling is covered by ResumingJobTest. */
class RevokeGoneFilesSessionsJobTest extends TestCase {
	private const NOW = 1_000_000;

	public function testEndsWithNothingLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');

		$this->start(['left' => [], 'failed' => false], $jobList, ['groupIds' => ['g.one', 'g.two']], ['g.one', 'g.two']);
	}

	public function testComesBackWithOnlyWhatIsLeft(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['groupIds' => ['g.two']]);

		$this->start(['left' => ['g.two'], 'failed' => false], $jobList, ['groupIds' => ['g.one', 'g.two']], ['g.one', 'g.two']);
	}

	/** A run that got nowhere backs off, or the next would do the same, without end. */
	public function testARunThatGotNowhereBacksOff(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(RevokeGoneFilesSessionsJob::class, self::NOW + 60, ['groupIds' => ['g.one'], 'attempt' => 1]);

		$this->start(['left' => ['g.one'], 'failed' => false], $jobList, ['groupIds' => ['g.one']], ['g.one']);
	}

	/** Giving up names how many groups, and one to look for. */
	public function testGivesUpNamingAGroupToLookFor(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('scheduleAfter');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Gave up'), ['app' => 'etherpad_nextcloud', 'groups' => 2, 'groupId' => 'g.one']);

		$this->start(['left' => ['g.one', 'g.two'], 'failed' => true], $jobList, ['groupIds' => ['g.one', 'g.two'], 'attempt' => 3], ['g.one', 'g.two'], $logger);
	}

	public function testANextRunThatCannotBeQueuedSaysSo(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willThrowException(new \RuntimeException('database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not queue'), $this->callback(static fn (array $context): bool => $context['app'] === 'etherpad_nextcloud' && $context['groups'] === 1 && $context['groupId'] === 'g.two' && isset($context['error'])));

		$this->start(['left' => ['g.two'], 'failed' => false], $jobList, ['groupIds' => ['g.one', 'g.two']], ['g.one', 'g.two'], $logger);
	}

	/** @return iterable<string, array{mixed}> */
	public static function notArguments(): iterable {
		yield 'not an array' => ['10'];
		yield 'no groups' => [['attempt' => 1]];
		yield 'an empty list' => [['groupIds' => []]];
		yield 'no group among them' => [['groupIds' => ['', 5, [5, 6], null]]];
	}

	/** @param mixed $argument */
	#[\PHPUnit\Framework\Attributes\DataProvider('notArguments')]
	public function testAnArgumentNamingNoGroupDoesNothing($argument): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->never())->method('pass');

		$this->run_($revoker, $this->createMock(IJobList::class), $argument);
	}

	/** Empty strings and non-string values are discarded; remaining strings keep their order. */
	public function testLeavesOutWhatIsNoGroup(): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with(['g.one', 'g.two'])->willReturn(['left' => [], 'failed' => false]);

		$this->run_($revoker, $this->createMock(IJobList::class), ['groupIds' => [5, 'g.one', '', [1], 'g.two']]);
	}

	/**
	 * @param array{left:list<string>,failed:bool} $pass
	 * @param array<string,mixed> $argument
	 * @param list<string> $groupIds
	 */
	private function start(array $pass, IJobList $jobList, array $argument, array $groupIds, ?LoggerInterface $logger = null): void {
		$revoker = $this->createMock(GoneFileSessionRevoker::class);
		$revoker->expects($this->once())->method('pass')->with($groupIds)->willReturn($pass);
		$this->run_($revoker, $jobList, $argument, $logger);
	}

	/** @param mixed $argument */
	private function run_(GoneFileSessionRevoker $revoker, IJobList $jobList, $argument, ?LoggerInterface $logger = null): void {
		$job = new RevokeGoneFilesSessionsJob(new FixedClock(self::NOW), $revoker, $jobList, $logger ?? $this->createMock(LoggerInterface::class));
		$job->setArgument($argument);
		$job->start($jobList);
	}
}
