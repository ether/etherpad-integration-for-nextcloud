<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The one Etherpad answer a delete may treat as success — and only about
 * the thing that was actually being deleted.
 */
class EtherpadErrorClassifierTest extends TestCase {
	/**
	 * @param string $message
	 * @param bool $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('padAnswers')]
	public function testReadsAPadAsGone(string $message, bool $expected): void {
		self::assertSame($expected, EtherpadErrorClassifier::isPadAlreadyDeleted(new \RuntimeException($message)));
	}

	/** @return array<string,array{string,bool}> */
	public static function padAnswers(): array {
		return [
			'public pad' => ['padID does not exist', true],
			'group of a protected pad' => ['groupID does not exist', true],
			'older spelling' => ['unknown pad', true],
			// deleteGroup tears down that group's sessions, so a failing pad
			// delete can answer about one. Reading that as "the pad is gone"
			// drops a binding row over a sentence about a session.
			'a session answer is not about the pad' => ['sessionID does not exist', false],
			'anything else' => ['Connection timed out', false],
		];
	}

	public function testReadsASessionAsGoneOnlyForASessionAnswer(): void {
		self::assertTrue(EtherpadErrorClassifier::isSessionAlreadyGone(new \RuntimeException('sessionID does not exist')));
		self::assertFalse(EtherpadErrorClassifier::isSessionAlreadyGone(new \RuntimeException('padID does not exist')));
		self::assertFalse(EtherpadErrorClassifier::isSessionAlreadyGone(new \RuntimeException('Connection timed out')));
	}

	/** The client wraps Etherpad's text, so the answer arrives as a cause. */
	public function testLooksThroughTheWrapping(): void {
		$wrapped = new \RuntimeException('Etherpad API request failed: deletePad', 0, new \RuntimeException('padID does not exist'));

		self::assertTrue(EtherpadErrorClassifier::isPadAlreadyDeleted($wrapped));
	}
}
