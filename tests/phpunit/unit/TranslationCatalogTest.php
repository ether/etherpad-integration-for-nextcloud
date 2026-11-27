<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * l10n/ is generated from translationfiles/ by scripts/l10n.sh (see
 * docs/i18n.md). A locale may be partly translated, but it must not carry
 * a string the gettext template no longer has, and its `.js` and `.json`
 * must agree.
 */
class TranslationCatalogTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';
	private const POT = self::ROOT . '/translationfiles/templates/etherpad_nextcloud.pot';
	/** Locales seeded before translatewiki.net; losing one is a regression, not a sync. */
	private const SEEDED = ['de', 'es', 'fr', 'it'];

	public function testTheTemplateHoldsStrings(): void {
		$this->assertNotSame([], $this->templateStrings(), 'no msgid was read from the template');
	}

	public function testEveryCatalogHasAPoFile(): void {
		foreach ($this->locales() as $locale) {
			$this->assertFileExists(
				self::ROOT . "/translationfiles/$locale/etherpad_nextcloud.po",
				"l10n/$locale.json has no translationfiles/$locale/etherpad_nextcloud.po to be built from",
			);
		}
	}

	public function testTheSeededLocalesAreStillShipped(): void {
		$this->assertSame([], array_values(array_diff(self::SEEDED, $this->locales())), 'a seeded locale has no l10n/*.json');
	}

	public function testNoCatalogCarriesAStringTheTemplateDropped(): void {
		$template = array_flip($this->templateStrings());
		foreach ($this->locales() as $locale) {
			$orphans = array_keys(array_diff_key($this->readJson($locale), $template));
			$this->assertSame([], $orphans, "l10n/$locale.json holds strings the template does not");
		}
	}

	public function testEachLocalesTwoFormatsAgree(): void {
		foreach ($this->locales() as $locale) {
			$this->assertSame(
				$this->readJson($locale),
				$this->readJs($locale),
				"l10n/$locale.js and l10n/$locale.json disagree",
			);
		}
	}

	/** @return list<string> */
	private function locales(): array {
		$locales = [];
		foreach ((array)glob(self::ROOT . '/l10n/*.json') as $path) {
			$locales[] = basename((string)$path, '.json');
		}
		$this->assertNotSame([], $locales, 'no l10n/*.json catalog was found');
		return $locales;
	}

	/**
	 * Every non-empty msgid of the template, continuation lines joined.
	 *
	 * @return list<string>
	 */
	private function templateStrings(): array {
		$this->assertFileExists(self::POT);
		$strings = [];
		$current = null;
		foreach (explode("\n", (string)file_get_contents(self::POT)) as $line) {
			if (str_starts_with($line, 'msgid ')) {
				$current = $this->unquote(substr($line, 6));
				continue;
			}
			if ($current !== null && str_starts_with($line, '"')) {
				$current .= $this->unquote($line);
				continue;
			}
			if ($current !== null && $current !== '') {
				$strings[] = $current;
			}
			$current = null;
		}
		return $strings;
	}

	private function unquote(string $quoted): string {
		$decoded = json_decode($quoted, false, 512, JSON_THROW_ON_ERROR);
		$this->assertIsString($decoded);
		return $decoded;
	}

	/** @return array<string,string> */
	private function readJson(string $locale): array {
		$path = self::ROOT . "/l10n/$locale.json";
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
		$path = self::ROOT . "/l10n/$locale.js";
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
