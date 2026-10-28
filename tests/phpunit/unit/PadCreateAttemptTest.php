<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadCreateAttempt;
use PHPUnit\Framework\TestCase;

class PadCreateAttemptTest extends TestCase {
	public function testAFreshAttemptOwnsNothing(): void {
		$attempt = new PadCreateAttempt();

		$this->assertNull($attempt->claim(), 'nothing created, nothing to roll back');
		$this->assertSame('', $attempt->path());
	}

	/** Claimed a moment before it turned out to be somebody else's pad. */
	public function testADisownedFileIsNotHandedToTheRollback(): void {
		$attempt = new PadCreateAttempt();
		$attempt->claimFile('alice', 4242);

		$attempt->disownFile();

		$this->assertNull($attempt->claim());
	}

	/** The same instance, not a copy: what the write records on it, the rollback reads. */
	public function testTheWrittenProofReachesTheRollback(): void {
		$attempt = new PadCreateAttempt();
		$claim = $attempt->claimFile('alice', 4242);

		$claim->writtenHash = hash('sha256', 'our document');

		$this->assertSame($claim, $attempt->claim());
		$this->assertSame(hash('sha256', 'our document'), $attempt->claim()?->writtenHash);
	}

	public function testCarriesWhatTheFlowRecords(): void {
		$attempt = new PadCreateAttempt();

		$attempt->claimFile('alice', 4242);
		$attempt->recordPath('/Notes/Meeting.pad');

		$this->assertSame('alice', $attempt->claim()?->uid);
		$this->assertSame(4242, $attempt->claim()?->fileId);
		$this->assertSame('', $attempt->claim()?->expectedBefore());
		$this->assertSame('/Notes/Meeting.pad', $attempt->path());
	}
}
