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
use OCA\EtherpadNextcloud\Tests\Support\WatchesTheWholeLogger;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The restore's legacy hook lets nothing out, for the reasons
 * TrashbinHookHandler gives, and says whose hook it was.
 */
class TrashbinHookHandlerTest extends TestCase {
	use WatchesTheWholeLogger;

	protected function tearDown(): void {
		Server::reset();
		parent::tearDown();
	}

	/** @return iterable<string, array{\Throwable}> */
	public static function failureProvider(): iterable {
		yield 'an exception' => [new \RuntimeException('the container is broken')];
		yield 'an error, which Nextcloud 31 does not catch' => [new \TypeError('the container is broken')];
	}

	/** A hook that could not start is reported under this app, and ends here. */
	#[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
	public function testAFailedLookupIsReportedUnderThisAppAndEndsHere(\Throwable $boom): void {
		$logger = $this->loggerExpecting('Legacy trashbin restore hook could not start.', $boom);
		Server::$registered = [
			LoggerInterface::class => $logger,
			RestoreFromTrashListener::class => $boom,
		];

		// No expectException: nothing may leave the hook.
		TrashbinHookHandler::postRestore(['filePath' => '/Notes.pad']);
	}

	/**
	 * The listener reports what it could not do and lets that go no
	 * further, so whatever still arrives here is unreported: it is
	 * reported here, once, and ends here like the rest.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
	public function testWhatTheListenerLetsThroughIsReportedHereAndEndsHere(\Throwable $boom): void {
		$listener = $this->createMock(RestoreFromTrashListener::class);
		$listener->method('handleLegacyHook')->willThrowException($boom);
		Server::$registered = [
			LoggerInterface::class => $this->loggerExpecting('Legacy trashbin restore hook failed.', $boom),
			RestoreFromTrashListener::class => $listener,
		];

		// No expectException: nothing may leave the hook.
		TrashbinHookHandler::postRestore(['filePath' => '/Notes.pad']);
	}

	/** @return iterable<string, array{object}> */
	public static function noLoggerProvider(): iterable {
		yield 'the listener cannot be built' => [new \TypeError('no listener')];
		yield 'the listener throws' => [new class {
			public function handleLegacyHook(array $params): void {
				throw new \TypeError('the listener broke');
			}
		}];
	}

	/** Not even the logger to be had: nobody is told, and still nothing leaves, at either catch. */
	#[\PHPUnit\Framework\Attributes\DataProvider('noLoggerProvider')]
	public function testNothingLeavesWithoutALoggerEither(object $listener): void {
		Server::$registered = [
			LoggerInterface::class => new \TypeError('no logger'),
			RestoreFromTrashListener::class => $listener,
		];

		TrashbinHookHandler::postRestore(['filePath' => '/Notes.pad']);
		$this->addToAssertionCount(1);
	}

	/**
	 * The hook fires for every restored item, folders included. Anything
	 * but a .pad is left before the listener is built, and without a word.
	 */
	public function testAnotherItemBuildsNothing(): void {
		$listener = $this->createMock(RestoreFromTrashListener::class);
		$listener->expects($this->never())->method('handleLegacyHook');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		Server::$registered = [
			LoggerInterface::class => $logger,
			RestoreFromTrashListener::class => $listener,
		];

		foreach ([['filePath' => '/Photos'], ['filePath' => '/Photos/Holiday.jpg'], [], ['filePath' => 42]] as $params) {
			TrashbinHookHandler::postRestore($params);
		}
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

	/** A logger that expects this one line at error level, for $boom, and nothing else. */
	private function loggerExpecting(string $message, \Throwable $boom): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$this->closeEveryLevelExcept($logger, 'error');
		$logger->expects($this->once())
			->method('error')
			->with(
				$message,
				$this->callback(function (array $context) use ($boom): bool {
					$this->assertSame('etherpad_nextcloud', $context['app']);
					// For groupfolders the only trace, so it names the file.
					$this->assertSame('/Notes.pad', $context['filePath']);
					$this->assertSame($boom::class, $context['error']);
					$this->assertStringContainsString($boom->getMessage(), $context['error_message']);
					// No exception object, whatever the shape of the failure.
					$this->assertArrayNotHasKey('exception', $context);
					return true;
				}),
			);
		return $logger;
	}
}
