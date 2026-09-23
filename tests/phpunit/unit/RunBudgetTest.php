<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\RunBudget;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class RunBudgetTest extends TestCase {
	/**
	 * Each call gets what is left, never more than a call a user waits on,
	 * and none is started that could not finish in time.
	 */
	public function testGivesEachCallWhatIsLeftAndStartsNoneThatCannotFinish(): void {
		$clock = new FixedClock();
		$budget = new RunBudget($clock, 20.0);

		$this->assertFalse($budget->exhausted());
		$this->assertSame(15, $budget->callTimeout());

		$clock->advance(12);
		$this->assertFalse($budget->exhausted());
		$this->assertSame(8, $budget->callTimeout());

		$clock->advance(7);
		$this->assertTrue($budget->exhausted());
		$this->assertSame(1, $budget->callTimeout());

		$clock->advance(5);
		$this->assertSame(0, $budget->callTimeout());
	}

	/** A few items without an answer read as an outage, however much time is left. */
	public function testEndsTheRunAfterAFewFailures(): void {
		$budget = new RunBudget(new FixedClock(), 20.0);

		for ($i = 0; $i < 4; $i++) {
			$budget->noteFailure();
		}
		$this->assertFalse($budget->exhausted());
		$budget->noteFailure();
		$this->assertTrue($budget->exhausted());
		$this->assertSame(5, $budget->failures());
	}

	/** A call after the first gets a timeout only while it could still finish. */
	public function testGivesTheNextCallATimeoutOnlyWhileItFits(): void {
		$clock = new FixedClock();
		$budget = new RunBudget($clock, 20.0);

		$this->assertSame(15, $budget->nextCallTimeout());
		$clock->advance(18);
		$this->assertSame(2, $budget->nextCallTimeout());
		$clock->advance(1);
		$this->assertNull($budget->nextCallTimeout());
	}
}
