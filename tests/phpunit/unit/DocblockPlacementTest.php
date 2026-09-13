<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two docblocks in a row mean one of them describes nothing: the lower one
 * wins, and whatever the upper one documented is now undocumented. It reads
 * as prose either way, so nothing else notices - reflection, IDEs and
 * readers all take the last block before the declaration.
 *
 * It happens when a method is inserted above an existing one and the
 * docblock already there is left where it was.
 */
class DocblockPlacementTest extends TestCase {
	/** @return iterable<string,array{string}> */
	public static function scannedDirectories(): iterable {
		yield 'lib' => [__DIR__ . '/../../../lib'];
		yield 'tests' => [__DIR__ . '/../../../tests/phpunit'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('scannedDirectories')]
	public function testNoDocblockSitsAboveAnother(string $directory): void {
		$stacked = [];
		foreach ($this->phpFilesIn($directory) as $file) {
			foreach ($this->stackedDocblockLines((string)file_get_contents($file)) as $line) {
				$stacked[] = basename($file) . ':' . $line;
			}
		}

		$this->assertSame([], $stacked, 'A docblock directly above another documents nothing');
	}

	/**
	 * @return list<int> the 1-based line of each `*​/` immediately followed by
	 *   a `/**` on the next non-blank line
	 */
	private function stackedDocblockLines(string $source): array {
		$lines = explode("\n", $source);
		$found = [];
		foreach ($lines as $i => $line) {
			if (trim($line) !== '*/') {
				continue;
			}
			$next = $i + 1;
			while ($next < count($lines) && trim($lines[$next]) === '') {
				$next++;
			}
			if ($next < count($lines) && str_starts_with(trim($lines[$next]), '/**')) {
				$found[] = $i + 1;
			}
		}
		return $found;
	}

	/** @return iterable<string> */
	private function phpFilesIn(string $directory): iterable {
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
		foreach ($iterator as $entry) {
			if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
				yield $entry->getPathname();
			}
		}
	}
}
