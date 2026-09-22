<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Hooks\TrashbinHookHandler;
use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OC_Hook::emit swallows an exception a slot throws - an error too, from
 * Nextcloud 32 - and lets the restore go on, and the entry it writes
 * carries no app, so this handler is the only thing that can say whose
 * hook never started. Everything after the
 * lookup belongs to the listener, which reports its own failures.
 */
class TrashbinHookHandlerTest extends TestCase {
	protected function tearDown(): void {
		Server::reset();
		parent::tearDown();
	}

	public function testAFailedLookupIsReportedUnderThisAppAndPassedOn(): void {
		$boom = new \RuntimeException('the container is broken');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(function (array $context) use ($boom): bool {
					$this->assertSame('etherpad_nextcloud', $context['app']);
					$this->assertSame(\RuntimeException::class, $context['error']);
					$this->assertStringContainsString($boom->getMessage(), $context['error_message']);
					// No exception object, whatever the shape of the failure.
					$this->assertArrayNotHasKey('exception', $context);
					return true;
				}),
			);

		Server::$registered = [
			LoggerInterface::class => $logger,
			RestoreFromTrashListener::class => $boom,
		];

		$this->expectExceptionObject($boom);
		TrashbinHookHandler::postRestore(['filePath' => '/Notes.pad']);
	}

	/**
	 * What the listener could not do is the listener's to report, so a
	 * failure after the lookup passes through here without a word.
	 */
	public function testAListenerFailureIsNotReportedAgainHere(): void {
		$boom = new \RuntimeException('lifecycle exploded');

		$listener = $this->createMock(RestoreFromTrashListener::class);
		$listener->method('handleLegacyHook')->willThrowException($boom);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		Server::$registered = [
			LoggerInterface::class => $logger,
			RestoreFromTrashListener::class => $listener,
		];

		$this->expectExceptionObject($boom);
		TrashbinHookHandler::postRestore(['filePath' => '/Notes.pad']);
	}

	public function testTheParametersReachTheListener(): void {
		$params = ['filePath' => '/Notes.pad'];

		$listener = $this->createMock(RestoreFromTrashListener::class);
		$listener->expects($this->once())->method('handleLegacyHook')->with($params);

		Server::$registered = [
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
			RestoreFromTrashListener::class => $listener,
		];

		TrashbinHookHandler::postRestore($params);
	}
}
