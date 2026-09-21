<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\SafeError;
use PHPUnit\Framework\TestCase;

class SafeErrorTest extends TestCase {
	public function testContextNamesTheFailureWithoutTheObject(): void {
		$context = SafeError::context(new \RuntimeException('boom'));

		$this->assertSame(\RuntimeException::class, $context['error']);
		$this->assertSame('boom', $context['error_message']);
		$this->assertArrayNotHasKey('exception', $context);
	}

	/**
	 * This app wraps a transport failure as "Etherpad API request failed",
	 * and a wrapper's trace starts at the catch - so taking it would report
	 * where the request began and never where it broke.
	 */
	public function testTheFramesComeFromTheInnermostCause(): void {
		$origin = SafeError::originOf($this->wrapped());

		$this->assertStringContainsString('deepestCall', $origin);
		$this->assertStringContainsString('connection refused', $origin);
	}

	/**
	 * getTrace() starts at the caller of the frame that built the
	 * exception, so the line that threw is in no frame at all. Most of what
	 * this app logs has no cause to fall back on, which would leave the
	 * origin pointing one call too early.
	 */
	public function testAFailureWithNoCauseIsPlacedAtTheLineThatThrew(): void {
		try {
			$this->deepestCall();
			self::fail('expected the call to throw');
		} catch (\Throwable $e) {
			$origin = SafeError::originOf($e);
		}

		$this->assertStringContainsString($e->getFile() . ':' . $e->getLine(), $origin);
		// And that is not the same place as the first frame, which is what
		// made the miss invisible: both are in this file, a line apart.
		$first = $e->getTrace()[0];
		$this->assertNotSame($e->getLine(), $first['line']);
	}

	public function testKnownSecretsAreRemovedFromBothHalves(): void {
		$context = SafeError::context(
			new \RuntimeException('rejected sekrit-value-123'),
			['sekrit-value-123'],
		);

		$this->assertStringNotContainsString('sekrit-value-123', $context['error_message']);
		$this->assertStringNotContainsString('sekrit-value-123', $context['error_origin']);
	}

	/**
	 * The cut is what makes a straddling secret unmatchable, so looking for
	 * the whole value would pass against exactly the defect.
	 */
	public function testASecretStraddlingTheCutIsStillRemoved(): void {
		$secret = 'sekrit-session-abcdefghijklmnop';
		// Placed so a long piece of it sits on either side of the 400
		// character cut: with the order reversed, str_replace no longer
		// finds the whole value and that piece is what survives.
		$long = str_repeat('x', 360) . ' sessionID=' . $secret . ' ' . str_repeat('y', 60);

		$context = SafeError::context(new \RuntimeException($long), [$secret]);

		$this->assertStringNotContainsString(substr($secret, 0, 12), $context['error_message']);
		$this->assertStringNotContainsString(substr($secret, 0, 12), $context['error_origin']);
	}

	private function wrapped(): \Throwable {
		try {
			$this->deepestCall();
		} catch (\Throwable $inner) {
			return new \LogicException('Etherpad API request failed: getText', 0, $inner);
		}
		self::fail('expected the inner call to throw');
	}

	private function deepestCall(): void {
		throw new \RuntimeException('connection refused');
	}
}
