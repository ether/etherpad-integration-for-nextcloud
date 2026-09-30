<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Tests\Support\AppSource;
use OCA\EtherpadNextcloud\Tests\Support\NamesOfSecrets;
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
	 * Parameters under a document's name that hold no document, each with
	 * what it holds instead. Not for a document in a method that cannot
	 * throw, or on a route that catches everything: those are properties
	 * of today's code.
	 */
	private const NOT_A_DOCUMENT = [
		'OCA\\EtherpadNextcloud\\Migration\\RegisterMimeType::writeAtomically($contents)' => 'the MIME type mapping the repair step writes',
		'OCA\\EtherpadNextcloud\\Service\\BaseUrlReachabilityCheck::fill($text)' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\CookieDomainMessages::fill($text)' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\EtherpadHealthCheckService::fill($text)' => 'a translated sentence with placeholders',
		'OCA\\EtherpadNextcloud\\Service\\PadPlaceholderResolver::applyForPath($content)' => 'a file name with placeholders',
	];

	/** Parameters under a credential's name that open nothing, each with what it holds. */
	private const NOT_A_CREDENTIAL = [
		'OCA\\EtherpadNextcloud\\Service\\AllowlistNormalizer::normalizeHost($sourceToken)' => 'one entry of an allowlist, as the admin typed it',
		'OCA\\EtherpadNextcloud\\Service\\AllowlistNormalizer::invalidHost($sourceToken)' => 'one entry of an allowlist, as the admin typed it',
		'OCA\\EtherpadNextcloud\\Service\\CookieDomainPolicy::isCoveredBy($cookieDomain)' => 'the domain a cookie is set for',
		'OCA\\EtherpadNextcloud\\Service\\HealthCheckResult::__construct($sessionCookieRelease)' => 'the Etherpad release that made the session cookie HttpOnly',
	];

	/**
	 * A list of names is checked for what it holds by the test above, and
	 * for what it lacks by nothing: take an entry out and every other test
	 * stays green. So the source is read for the other half - every method
	 * under lib that takes a document as a string is on the list.
	 *
	 * By the parameter's name, which is all a signature says of a string.
	 * A document under another name passes.
	 */
	public function testEveryMethodThatTakesADocumentAsAStringIsRegistered(): void {
		[$missing, $idle] = self::unregistered(NamesOfSecrets::DOCUMENT, false, self::NOT_A_DOCUMENT);

		$this->assertSame([], $missing, 'takes a document as a string and is not registered as sensitive');
		$this->assertSame([], $idle, 'an exception nothing needs any more');
	}

	/**
	 * The same for a credential, which also travels as a list: session
	 * ids, the secrets to take out of a message. Where it has no name of
	 * its own - the settings form as it was sent, with the API key in it;
	 * a share's token inside the author id made of it - the list names the
	 * method by hand and this cannot tell.
	 */
	public function testEveryMethodThatTakesACredentialIsRegistered(): void {
		[$missing, $idle] = self::unregistered(NamesOfSecrets::CREDENTIAL, true, self::NOT_A_CREDENTIAL);

		$this->assertSame([], $missing, 'takes a credential as a string or a list and is not registered as sensitive');
		$this->assertSame([], $idle, 'an exception nothing needs any more');
	}

	/**
	 * The parameters named by $names in methods that are not registered,
	 * each as `Class::method($parameter)`, but for the ones $excused - and
	 * the excuses no parameter needs: one for a parameter that is gone, or
	 * whose method is registered anyway, would excuse the next of that
	 * name, or say of the method what is not so.
	 *
	 * An excuse is for one parameter. A second one of the kind in the same
	 * method is found like any other.
	 *
	 * An object under such a name is a carrier, and keeps what it carries
	 * to itself (DocumentStaysOutOfTracesTest, CredentialStaysOutOfTracesTest).
	 *
	 * @param array<string,string> $excused
	 * @return array{list<string>, list<string>}
	 */
	private static function unregistered(string $names, bool $alsoAsAList, array $excused): array {
		$missing = [];
		$seen = [];
		foreach (AppSource::methods() as [$class, $method, $parameters]) {
			if (in_array($method, SensitiveMethods::ALL[$class] ?? [], true)) {
				continue;
			}
			foreach ($parameters as [$type, $name]) {
				if (!self::isPlain($type, $alsoAsAList) || preg_match($names, $name) !== 1) {
					continue;
				}
				$key = $class . '::' . $method . '($' . $name . ')';
				$seen[$key] = true;
				if (!isset($excused[$key])) {
					$missing[] = $key;
				}
			}
		}
		return [$missing, array_values(array_diff(array_keys($excused), array_keys($seen)))];
	}

	/** Whether a parameter of this type holds its value where a trace prints it: a string, untyped, and if asked a list. */
	private static function isPlain(string $type, bool $alsoAsAList): bool {
		$types = $type === '' ? ['mixed'] : explode('|', ltrim(strtolower($type), '?'));
		return array_intersect($types, $alsoAsAList ? ['mixed', 'string', 'array', 'iterable'] : ['mixed', 'string']) !== [];
	}
}
