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
			foreach (self::stackedDocblockLines((string)file_get_contents($file)) as $line) {
				$stacked[] = basename($file) . ':' . $line;
			}
		}

		$this->assertSame([], $stacked, 'A docblock directly above another documents nothing');
	}

	/**
	 * Read as tokens rather than lines: a single-line `/** ... *​/` closes on
	 * the same line it opens, so looking for a lone `*​/` misses every pair
	 * where the upper block is written that way - and those are common here.
	 *
	 * @return list<int> the 1-based line each discarded block starts on
	 */
	public static function stackedDocblockLines(string $source): array {
		$found = [];
		$openDocLine = null;
		foreach (token_get_all($source) as $token) {
			if (!is_array($token)) {
				$openDocLine = null;
				continue;
			}
			[$id, $text, $line] = $token;
			if ($id === T_DOC_COMMENT) {
				if ($openDocLine !== null) {
					$found[] = $openDocLine;
				}
				$openDocLine = $line;
				continue;
			}
			// Blank lines between two blocks do not separate them: the lower
			// one still wins.
			if ($id !== T_WHITESPACE) {
				$openDocLine = null;
			}
		}
		return $found;
	}

	/**
	 * The shape that made the line-based version miss one: the upper block
	 * closes on the line it opens, so there is no lone `*​/` to find.
	 */
	public function testFindsASingleLineBlockAboveAnother(): void {
		$source = <<<'PHP'
		<?php
		class Example {
			/** @return array|null */
			/**
			 * What this really does.
			 */
			public function thing(): bool {
				return true;
			}
		}
		PHP;

		self::assertSame([3], self::stackedDocblockLines($source));
	}

	/** One block on its own is what every documented method looks like. */
	public function testAcceptsASingleBlock(): void {
		$source = <<<'PHP'
		<?php
		class Example {
			/** @return bool */
			public function thing(): bool {
				return true;
			}
		}
		PHP;

		self::assertSame([], self::stackedDocblockLines($source));
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
