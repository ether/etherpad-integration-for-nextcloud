<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\RunBudgetSpentException;
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
			$budget->noteUnanswered();
		}
		$this->assertFalse($budget->exhausted());
		$budget->noteUnanswered();
		$this->assertTrue($budget->exhausted());
	}

	/** Twenty refusals in a row end a run, an item that went through starts them again, and fifty in all end it. */
	public function testEndsTheRunAfterSoManyRefusals(): void {
		$inARow = new RunBudget(new FixedClock(), 20.0);
		for ($i = 0; $i < 19; $i++) {
			$inARow->noteRefusal();
		}
		$this->assertFalse($inARow->exhausted());
		$inARow->noteRefusal();
		$this->assertTrue($inARow->exhausted());

		$inAll = new RunBudget(new FixedClock(), 20.0);
		for ($i = 1; $i < 50; $i++) {
			$inAll->noteRefusal();
			if ($i % 10 === 0) {
				$inAll->noteDone();
			}
		}
		$this->assertFalse($inAll->exhausted());
		$inAll->noteRefusal();
		$this->assertTrue($inAll->exhausted());
	}

	/** A call that went through starts the failures again, not the refusals in a row. */
	public function testACallGoneThroughStartsOnlyTheFailuresAgain(): void {
		$budget = new RunBudget(new FixedClock(), 20.0);
		for ($i = 0; $i < 4; $i++) {
			$budget->noteUnanswered();
		}
		$budget->noteGoneThrough();
		for ($i = 0; $i < 4; $i++) {
			$budget->noteUnanswered();
		}
		$this->assertFalse($budget->exhausted());

		for ($i = 0; $i < 19; $i++) {
			$budget->noteRefusal();
		}
		$budget->noteGoneThrough();
		$budget->noteRefusal();
		$this->assertTrue($budget->exhausted());
	}

	/**
	 * The clock is read once for a call's timeout: a clock that moves
	 * between two readings must not let a call start that then gets less
	 * than the least a call is given.
	 */
	public function testReadsTheClockOnceForACallsTimeout(): void {
		$clock = new class(0) extends FixedClock {
			public function now(): \DateTimeImmutable {
				$this->advanceMicros(600_000);
				return parent::now();
			}
		};
		$budget = RunBudget::forRequest($clock, 2.0);

		$this->assertSame(1, $budget->nextCallTimeout());
	}

	/** A call gets a timeout only while it could still finish. */
	public function testGivesTheNextCallATimeoutOnlyWhileItFits(): void {
		$clock = new FixedClock();
		$budget = new RunBudget($clock, 20.0);

		$this->assertSame(15, $budget->nextCallTimeout());
		$clock->advance(18);
		$this->assertSame(2, $budget->nextCallTimeout());
		$clock->advance(1);
		$this->assertNull($budget->nextCallTimeout());
	}

	/**
	 * A request's budget is a couple of seconds: a call fits with one
	 * second left, and no call is spent on asking whether Etherpad answers.
	 */
	public function testARequestsBudgetStartsACallWithASecondLeftAndAsksNothing(): void {
		$clock = new FixedClock();
		$budget = RunBudget::forRequest($clock, 2.0);

		$this->assertSame(2, $budget->nextCallTimeout());
		for ($i = 0; $i < 5; $i++) {
			$budget->noteUnanswered(fn (): bool => $this->fail('a request asks nothing'));
		}
		$this->assertTrue($budget->exhausted());
		$clock->advance(1);
		$this->assertSame(1, $budget->nextCallTimeout());
		$clock->advanceMicros(1);
		$this->assertNull($budget->nextCallTimeout());
	}

	/**
	 * A background run counts an item without an answer only when Etherpad
	 * then does not answer at all, or cannot be asked in time; and an item
	 * that went through starts the count again.
	 */
	public function testABackgroundRunAsksBeforeItCountsAnOutage(): void {
		$budget = new RunBudget(new FixedClock(), 20.0);
		for ($i = 0; $i < 10; $i++) {
			$budget->noteUnanswered(static fn (): bool => true);
		}
		$this->assertFalse($budget->exhausted(), 'Etherpad answers: not an outage');

		for ($i = 0; $i < 4; $i++) {
			$budget->noteUnanswered(static fn (): bool => false);
		}
		$budget->noteDone();
		for ($i = 0; $i < 4; $i++) {
			$budget->noteUnanswered(static fn (): bool => throw new RunBudgetSpentException('no time'));
		}
		$this->assertFalse($budget->exhausted(), 'four since the last that went through');
		$budget->noteUnanswered();
		$this->assertTrue($budget->exhausted());
	}

	/** One way to ask for a call's timeout, with a budget or without one. */
	public function testTimeoutOfFollowsTheBudgetOrLeavesTheClientsOwn(): void {
		$clock = new FixedClock();
		$budget = new RunBudget($clock, 20.0);

		$this->assertNull(RunBudget::timeoutOf(null));
		$this->assertSame(15, RunBudget::timeoutOf($budget));
		$clock->advance(19);
		$this->expectException(RunBudgetSpentException::class);
		RunBudget::timeoutOf($budget);
	}
}
