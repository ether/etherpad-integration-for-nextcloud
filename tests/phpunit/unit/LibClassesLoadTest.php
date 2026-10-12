<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Infection loads each class it mutates in a process of its own, with
 * nothing but the unit suite's bootstrap, and stops at the first one that
 * names an OCP type no stub provides - a class no unit test loads included.
 * The check runs in a separate PHP process for that same view: this one
 * holds whatever earlier tests loaded.
 */
class LibClassesLoadTest extends TestCase {
	public function testEveryClassUnderLibLoadsWithTheBootstrapAlone(): void {
		$output = [];
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Support/load-lib-classes.php') . ' 2>&1',
			$output,
			$status,
		);

		$this->assertSame([], $output);
		$this->assertSame(0, $status);
	}
}
