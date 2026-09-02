<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two questions, two string lists. One shared list would let an answer
 * about a session be read as an answer about a pad — and a pad delete that
 * failed while Etherpad tore down that group's sessions would then drop a
 * binding row for a pad that is still there.
 */
class EtherpadErrorClassifierSessionTest extends TestCase {
	public function testReadsAGoneSession(): void {
		self::assertTrue(EtherpadErrorClassifier::isSessionAlreadyGone(
			new EtherpadClientException('sessionID does not exist')
		));
	}

	/** The client wraps transport errors, so the sentence Etherpad said is rarely the outermost one. */
	public function testLooksThroughTheCauseChain(): void {
		self::assertTrue(EtherpadErrorClassifier::isSessionAlreadyGone(
			new EtherpadClientException('Etherpad call failed', 0, new \RuntimeException('sessionID does not exist'))
		));
	}

	#[DataProvider('padAnswers')]
	public function testDoesNotReadAPadAnswerAsASession(string $message): void {
		self::assertFalse(EtherpadErrorClassifier::isSessionAlreadyGone(
			new EtherpadClientException($message)
		));
	}

	/** @return iterable<string,array{string}> */
	public static function padAnswers(): iterable {
		yield 'pad' => ['padID does not exist'];
		yield 'group' => ['groupID does not exist'];
		yield 'timeout' => ['Connection timed out'];
	}

	/** And the other way round: a session answer is not a gone pad. */
	public function testASessionAnswerIsNotAGonePad(): void {
		self::assertFalse(EtherpadErrorClassifier::isPadAlreadyDeleted(
			new EtherpadClientException('sessionID does not exist')
		));
	}
}
