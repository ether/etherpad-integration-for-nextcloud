<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\ApiErrorCode;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use PHPUnit\Framework\TestCase;

class ApiErrorCodeTest extends TestCase {
	/**
	 * docs/api-reference.md lists the full set of codes, each with the
	 * exception it stands for. A code added, renamed or moved to another
	 * exception here and not there - or the other way round - fails.
	 */
	public function testTheApiReferenceListsExactlyTheseCodes(): void {
		$reference = (string)file_get_contents(__DIR__ . '/../../../docs/api-reference.md');
		preg_match_all('/^  - `([a-z_]+)` \(`([A-Za-z]+)`\)/m', $reference, $listed, PREG_SET_ORDER);

		$documented = [];
		foreach ($listed as [, $code, $exception]) {
			$documented[$code] = $exception;
		}
		$defined = [];
		foreach (ApiErrorCode::cases() as $code) {
			$class = $code->exceptionClass();
			$defined[$code->value] = substr($class, (int)strrpos($class, '\\') + 1);
		}
		ksort($documented);
		ksort($defined);

		$this->assertSame($defined, $documented);
	}

	/** Each code stands for its exception, and only that one: a parent type carries none. */
	public function testEachCodeStandsForItsException(): void {
		foreach (ApiErrorCode::cases() as $code) {
			$class = $code->exceptionClass();
			$this->assertSame($code, ApiErrorCode::of(new $class('')), $code->value);
		}
		foreach ([new BindingException('Binding pad ID mismatch.'), new PadFileFormatException('Invalid .pad file.'), new EtherpadClientException('Etherpad API request failed: getText'), new \RuntimeException('')] as $uncoded) {
			$this->assertNull(ApiErrorCode::of($uncoded), $uncoded::class);
		}
	}

	/**
	 * A payload gets the code and, for a row that waits, `retryable`; on a
	 * public share not the two whose action needs a signed-in user.
	 */
	public function testAPayloadGetsTheCodeAVisitorMayActOn(): void {
		$payload = ['message' => 'm'];
		foreach (ApiErrorCode::cases() as $code) {
			$class = $code->exceptionClass();
			$e = new $class('');
			$marked = $code === ApiErrorCode::WaitingBinding
				? ['message' => 'm', 'code' => $code->value, 'retryable' => true]
				: ['message' => 'm', 'code' => $code->value];

			$this->assertSame($marked, ApiErrorCode::addTo($payload, $e), $code->value);
			$this->assertSame(
				in_array($code, [ApiErrorCode::MissingBinding, ApiErrorCode::MissingFrontmatter], true) ? $payload : $marked,
				ApiErrorCode::addTo($payload, $e, onAPublicShare: true),
				$code->value . ' on a public share',
			);
		}
		$this->assertSame($payload, ApiErrorCode::addTo($payload, new \RuntimeException('')));
	}
}
