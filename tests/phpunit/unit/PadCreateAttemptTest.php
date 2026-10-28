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

	public function testCarriesWhatTheFlowRecords(): void {
		$attempt = new PadCreateAttempt();

		$claim = $attempt->claimFile('alice', 4242);
		$attempt->recordPath('/Notes/Meeting.pad');
		$this->assertTrue($claim->holds(''), 'the file it created is expected empty');
		// The same instance, not a copy: what the write records on it, the rollback reads.
		$claim->writtenHash = hash('sha256', 'our document');

		$this->assertSame($claim, $attempt->claim());
		$this->assertSame(
			['alice', 4242, hash('sha256', 'our document'), '/Notes/Meeting.pad'],
			[$claim->uid, $claim->fileId, $attempt->claim()?->writtenHash, $attempt->path()],
		);

		// Found to be somebody else's pad: nothing left for the rollback.
		$attempt->disownFile();
		$this->assertNull($attempt->claim());
	}
}
