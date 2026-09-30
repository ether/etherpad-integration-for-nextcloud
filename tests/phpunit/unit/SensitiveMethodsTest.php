<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\StoredAdminSettings;
use OCA\EtherpadNextcloud\Service\ValidatedAdminSettings;
use OCA\EtherpadNextcloud\Util\SensitiveMethods;
use PHPUnit\Framework\TestCase;

/**
 * The registration is a list of names, and a list of names goes stale in
 * silence: rename a method and Nextcloud simply stops replacing its
 * arguments, with nothing to show for it but a secret in a log nobody
 * reads until they need it.
 */
class SensitiveMethodsTest extends TestCase {
	/** @return iterable<string, array{class-string, string}> */
	public static function registeredMethodProvider(): iterable {
		foreach (SensitiveMethods::ALL as $class => $methods) {
			foreach ($methods as $method) {
				yield $class . '::' . $method => [$class, $method];
			}
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('registeredMethodProvider')]
	public function testEveryRegisteredMethodExists(string $class, string $method): void {
		$this->assertTrue(class_exists($class), $class . ' is registered but does not exist');
		$this->assertTrue(
			method_exists($class, $method),
			$class . '::' . $method . ' is registered as sensitive but does not exist',
		);
	}

	/**
	 * The names a document goes by as a parameter: what a pad says, as its
	 * text or HTML, a file's content or body, a template.
	 */
	private const NAMES_A_DOCUMENT = '/^(?:text|html|contents?|body|doc|document|expectedBefore)$|(?:Text|Html|Contents?|Body|Document|Doc)$/';

	/**
	 * Parameters under one of those names that hold no document, each with
	 * what it holds instead. Not for a document in a method that cannot
	 * throw, or on a route that catches everything: those are properties
	 * of today's code, and an entry on the list costs nothing.
	 */
	private const NOT_A_DOCUMENT = [
		'OCA\\EtherpadNextcloud\\Migration\\RegisterMimeType::writeAtomically' => 'the MIME type mapping the repair step writes',
		'OCA\\EtherpadNextcloud\\Service\\BaseUrlReachabilityCheck::fill' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\CookieDomainMessages::fill' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\EtherpadHealthCheckService::fill' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\PadPlaceholderResolver::applyForPath' => 'a file name with placeholders',
		'OCA\\EtherpadNextcloud\\Util\\DiagnosticText::withoutSecret' => 'a diagnostic sentence',
	];

	/**
	 * A list of names is checked for what it holds by the test above, and
	 * for what it lacks by nothing: take an entry out and every other test
	 * stays green. So the source is read for the other half - every method
	 * under lib that takes a document as a string is on the list.
	 *
	 * By the parameter's name, which is all a signature says of a string.
	 * A document under another name passes, and a credential is not looked
	 * for at all.
	 */
	public function testEveryMethodThatTakesADocumentAsAStringIsRegistered(): void {
		$missing = [];
		$seen = [];
		foreach (self::methodsOfTheApp() as [$class, $method, $parameters]) {
			$documents = [];
			foreach ($parameters as [$type, $name]) {
				// An object under such a name is a carrier, and keeps the
				// document to itself (DocumentStaysOutOfTracesTest).
				$isAString = $type === '' || $type === 'mixed' || str_contains($type, 'string');
				if ($isAString && preg_match(self::NAMES_A_DOCUMENT, $name) === 1) {
					$documents[] = '$' . $name;
				}
			}
			if ($documents === []) {
				continue;
			}
			$key = $class . '::' . $method;
			$seen[$key] = true;
			if (!isset(self::NOT_A_DOCUMENT[$key]) && !in_array($method, SensitiveMethods::ALL[$class] ?? [], true)) {
				$missing[] = $key . '(' . implode(', ', $documents) . ')';
			}
		}

		$this->assertSame([], $missing, 'takes a document as a string and is not registered as sensitive');
		// An exception for a method that is gone, or no longer takes such a
		// parameter, would excuse the next one of that name.
		$this->assertSame([], array_values(array_diff(array_keys(self::NOT_A_DOCUMENT), array_keys($seen))), 'an exception nothing needs any more');
	}

	/**
	 * Every named function under lib, with its parameters as written.
	 * Read from the source, not by reflection: the unit suite runs against
	 * stubs, and a class whose parent is none of them cannot be loaded.
	 *
	 * @return list<array{string, string, list<array{string, string}>}>
	 */
	private static function methodsOfTheApp(): array {
		$methods = [];
		$root = dirname(__DIR__, 3) . '/lib';
		/** @var iterable<\SplFileInfo> $files */
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $namespace) !== 1) {
				continue;
			}
			$class = $namespace[1] . '\\' . $file->getBasename('.php');
			preg_match_all('/\bfunction\s+(\w+)\s*\(/', $source, $found, PREG_OFFSET_CAPTURE);
			foreach ($found[1] as $index => [$name]) {
				$start = $found[0][$index][1] + strlen($found[0][$index][0]);
				$methods[] = [$class, $name, self::parametersFrom($source, $start)];
			}
		}
		self::assertGreaterThan(300, count($methods), 'the scan has to see the app');
		return $methods;
	}

	/**
	 * The parameters of the list that opens before $start: up to the
	 * bracket that closes it, a default value's own brackets skipped.
	 *
	 * @return list<array{string, string}> type as written, or '', and name
	 */
	private static function parametersFrom(string $source, int $start): array {
		$depth = 1;
		$end = $start;
		for ($length = strlen($source); $end < $length && $depth > 0; $end++) {
			$depth += match ($source[$end]) {
				'(', '[' => 1,
				')', ']' => -1,
				default => 0,
			};
		}
		preg_match_all(
			'/(?:(?<type>[?\w\\\\|&]+)\s+)?(?:&|\.\.\.)?\$(?<name>\w+)/',
			substr($source, $start, $end - $start - 1),
			$found,
			PREG_SET_ORDER,
		);
		return array_map(
			// What stands before a promoted parameter's name may be its
			// visibility alone: then it has no type.
			static fn (array $parameter): array => [
				in_array($parameter['type'], ['public', 'protected', 'private', 'readonly'], true) ? '' : $parameter['type'],
				$parameter['name'],
			],
			$found,
		);
	}

	/**
	 * The api key is on no list, because no frame holds it: it travels as
	 * ApiKey and the request body leaves as a stream. That only holds while
	 * the settings objects keep it out of get_object_vars(), which is what
	 * a serialized trace reads.
	 */
	public function testTheSettingsObjectsDoNotExposeTheApiKey(): void {
		$validated = new ValidatedAdminSettings(
			'https://pad.example.test',
			'https://pad-api.example.test',
			'.example.test',
			'secret-to-store',
			'secret-in-use',
			'1.3.0',
			120,
			true,
			false,
			'',
			'',
		);
		$stored = new StoredAdminSettings('stored-secret', '.example.test', true, false, '');

		$this->assertStringNotContainsString('secret', (string)json_encode(get_object_vars($validated)));
		$this->assertStringNotContainsString('secret', (string)json_encode(get_object_vars($stored)));
		// And they are still reachable where they are needed.
		$this->assertSame('secret-in-use', $validated->effectiveApiKey()->reveal());
		$this->assertSame('stored-secret', $stored->apiKey()->reveal());
	}
}
