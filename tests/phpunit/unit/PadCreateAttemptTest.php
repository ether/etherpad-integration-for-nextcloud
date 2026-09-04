<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadCreateAttempt;
use PHPUnit\Framework\TestCase;

/**
 * The two defects that motivated this object, written as statements about
 * it rather than as orderings of assignments in a closure.
 */
class PadCreateAttemptTest extends TestCase {
	public function testAFreshAttemptOwnsNothing(): void {
		$attempt = new PadCreateAttempt();

		$this->assertNull($attempt->claim(), 'nothing created, nothing to roll back');
		$this->assertSame('', $attempt->padId());
		$this->assertSame('', $attempt->path());
	}

	/**
	 * The file turned out to be somebody else's pad. It was recorded a
	 * moment earlier, and the old code depended on un-setting that variable
	 * before the throw a few lines below.
	 */
	public function testADisownedFileIsNotHandedToTheRollback(): void {
		$attempt = new PadCreateAttempt();
		$attempt->claimFile('alice', 4242);

		$attempt->disownFile();

		$this->assertNull($attempt->claim());
	}

	/**
	 * The claim handed to the write is the one the rollback reads, so proof
	 * of a successful write survives a later failure. Returning it by value
	 * was how that proof used to get lost.
	 */
	public function testTheWrittenProofReachesTheRollback(): void {
		$attempt = new PadCreateAttempt();
		$claim = $attempt->claimFile('alice', 4242);

		$claim->writtenHash = hash('sha256', 'our document');

		$this->assertSame($claim, $attempt->claim());
		$this->assertSame(hash('sha256', 'our document'), $attempt->claim()?->writtenHash);
	}

	public function testCarriesWhatTheFlowRecords(): void {
		$attempt = new PadCreateAttempt();

		$attempt->claimFile('alice', 4242, 'template bytes');
		$attempt->recordPad('g.ABC$pad');
		$attempt->recordPath('/Notes/Meeting.pad');

		$this->assertSame('alice', $attempt->claim()?->uid);
		$this->assertSame(4242, $attempt->claim()?->fileId);
		$this->assertSame('template bytes', $attempt->claim()?->expectedBefore);
		$this->assertSame('g.ABC$pad', $attempt->padId());
		$this->assertSame('/Notes/Meeting.pad', $attempt->path());
	}
}
