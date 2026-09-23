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

		$this->assertTrue($budget->fitsAnotherCall());
		$this->assertSame(15, $budget->callTimeout());

		$clock->advance(12);
		$this->assertTrue($budget->fitsAnotherCall());
		$this->assertSame(8, $budget->callTimeout());

		$clock->advance(7);
		$this->assertFalse($budget->fitsAnotherCall());
		$this->assertSame(1, $budget->callTimeout());

		$clock->advance(5);
		$this->assertSame(0, $budget->callTimeout());
	}
}
