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

	public function testKnownSecretsAreRemovedFromBothHalves(): void {
		$context = SafeError::context(
			new \RuntimeException('rejected sekrit-value-123'),
			['sekrit-value-123'],
		);

		$this->assertStringNotContainsString('sekrit-value-123', $context['error_message']);
		$this->assertStringNotContainsString('sekrit-value-123', $context['error_origin']);
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
