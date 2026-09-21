<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\LifecycleException;
use OCA\EtherpadNextcloud\Listeners\MoveToTrashListener;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Tests\Support\WiresALifecycleService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The trash half of the same arrangement as the restore listener: the
 * flow does not report its own failures, so this listener is the only
 * thing standing between one and the log.
 */
class MoveToTrashListenerTest extends TestCase {
	use WiresALifecycleService;

	public function testAFlowFailureIsReportedOnceAndPassedOn(): void {
		$fileId = 4711;
		$boom = new \RuntimeException('the storage went away');

		$bindingService = $this->createMock(BindingService::class);
		$bindingService->method('findByFileId')->willReturn([
			'file_id' => $fileId,
			'pad_id' => 'a-pad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn('Notes.pad');
		$file->method('getContent')->willThrowException($boom);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(function (array $context) use ($fileId, $boom): bool {
					$this->assertSame($fileId, $context['fileId']);
					$this->assertStringContainsString($boom->getMessage(), $context['error_origin']);
					return true;
				}),
			);

		$listener = new MoveToTrashListener($this->lifecycleServiceOver($bindingService, $logger), $logger);

		$this->expectException(LifecycleException::class);
		$listener->handle($this->trashEventFor($file));
	}

	/**
	 * Reading the id is one of the ways the flow fails, and reporting that
	 * failure reads it again. A second exception from inside the catch
	 * would replace the one being reported and leave nothing in the log.
	 */
	public function testAStaleNodeIsStillReported(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willThrowException(new NotFoundException());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(function (array $context): bool {
					$this->assertNull($context['fileId'], 'an id that cannot be read is absent, not invented');
					return true;
				}),
			);

		$lifecycleService = $this->createMock(LifecycleService::class);
		$lifecycleService->method('handleTrash')->willThrowException(new NotFoundException());

		$this->expectException(NotFoundException::class);
		(new MoveToTrashListener($lifecycleService, $logger))->handle($this->trashEventFor($file));
	}

	private function trashEventFor(File $file): Event {
		return new class($file) extends Event {
			public function __construct(private File $file) {
			}

			public function getNode(): File {
				return $this->file;
			}
		};
	}
}
