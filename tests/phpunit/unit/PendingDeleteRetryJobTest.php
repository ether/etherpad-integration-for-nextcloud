<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\BackgroundJob\ColdPendingDeleteRetryJob;
use OCA\EtherpadNextcloud\BackgroundJob\HotPendingDeleteRetryJob;
use OCA\EtherpadNextcloud\BackgroundJob\WarmPendingDeleteRetryJob;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class PendingDeleteRetryJobTest extends TestCase {
	public function testHotJobRetriesYoungRowsEveryFiveMinutes(): void {
		$retry = $this->createMock(PendingDeleteRetryService::class);
		$retry->expects($this->once())->method('retryByAge')->with(0, 3600, 200);

		$job = new HotPendingDeleteRetryJob(new FixedClock(), $retry);

		$this->assertSame(300, $job->getInterval());
		$this->runJob($job);
	}

	public function testWarmJobRetriesRowsFromFirstDayHourly(): void {
		$retry = $this->createMock(PendingDeleteRetryService::class);
		$retry->expects($this->once())->method('retryByAge')->with(3600, 86400, 200);

		$job = new WarmPendingDeleteRetryJob(new FixedClock(), $retry);

		$this->assertSame(3600, $job->getInterval());
		$this->runJob($job);
	}

	public function testColdJobRetriesOlderRowsDaily(): void {
		$retry = $this->createMock(PendingDeleteRetryService::class);
		$retry->expects($this->once())->method('retryByAge')->with(86400, null, 200);

		$job = new ColdPendingDeleteRetryJob(new FixedClock(), $retry);

		$this->assertSame(86400, $job->getInterval());
		$this->runJob($job);
	}

	private function runJob(object $job): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}
}
