<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\CreatedFileClaim;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreatedFileClaimTest extends TestCase {
	/** @return iterable<string,array{0:CreatedFileClaim,1:string,2:bool}> */
	public static function contentProvider(): iterable {
		yield 'untouched' => [new CreatedFileClaim('alice', 4242), '', true];
		yield 'as handed over' => [new CreatedFileClaim('alice', 4242, 'template bytes'), 'template bytes', true];
		yield 'written meanwhile' => [new CreatedFileClaim('alice', 4242), 'notes', false];
		yield 'its write' => [self::wrote('our document'), 'our document', true];
		yield 'another write' => [self::wrote('our document'), 'their document', false];
		yield 'emptied after its write' => [self::wrote('our document'), '', false];
		yield 'handed over again after its write' => [self::wrote('our document', 'template bytes'), 'template bytes', false];
	}

	#[DataProvider('contentProvider')]
	public function testHoldsOnlyWhatItExpectedOrWrote(CreatedFileClaim $claim, string $content, bool $holds): void {
		$this->assertSame($holds, $claim->holds($content));
	}

	private static function wrote(string $document, string $expectedBefore = ''): CreatedFileClaim {
		$claim = new CreatedFileClaim('alice', 4242, $expectedBefore);
		$claim->writtenHash = hash('sha256', $document);

		return $claim;
	}
}
