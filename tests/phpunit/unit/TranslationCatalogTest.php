<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docs/i18n.md requires every maintained locale to hold the same keys, and
 * a locale's `.js` and `.json` to agree. Both are kept by hand.
 */
class TranslationCatalogTest extends TestCase {
	private const LOCALES = ['de', 'es', 'fr', 'it'];

	public function testEveryLocaleCarriesTheSameKeys(): void {
		$reference = null;
		foreach (self::LOCALES as $locale) {
			$keys = array_keys($this->readJson($locale));
			sort($keys);
			if ($reference === null) {
				$reference = $keys;
				continue;
			}
			$this->assertSame(
				$reference,
				$keys,
				"l10n/$locale.json does not hold the same keys as the first locale",
			);
		}
		$this->assertNotNull($reference);
		$this->assertNotSame([], $reference, 'no catalog keys were read at all');
	}

	public function testEachLocalesTwoFormatsAgree(): void {
		foreach (self::LOCALES as $locale) {
			$this->assertSame(
				$this->readJson($locale),
				$this->readJs($locale),
				"l10n/$locale.js and l10n/$locale.json disagree",
			);
		}
	}

	/** @return array<string,string> */
	private function readJson(string $locale): array {
		$path = __DIR__ . "/../../../l10n/$locale.json";
		$this->assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('translations', $decoded);
		/** @var array<string,string> $translations */
		$translations = $decoded['translations'];
		ksort($translations);
		return $translations;
	}

	/**
	 * The `.js` catalog is an `OC.L10N.register(...)` call, so the object
	 * literal is lifted out and read as JSON rather than executed.
	 *
	 * @return array<string,string>
	 */
	private function readJs(string $locale): array {
		$path = __DIR__ . "/../../../l10n/$locale.js";
		$this->assertFileExists($path);
		$source = (string)file_get_contents($path);
		$open = strpos($source, '{');
		$close = strrpos($source, '}');
		$this->assertIsInt($open, "l10n/$locale.js has no object literal");
		$this->assertIsInt($close);
		$decoded = json_decode(substr($source, $open, $close - $open + 1), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		/** @var array<string,string> $decoded */
		ksort($decoded);
		return $decoded;
	}
}
