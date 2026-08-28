<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PadCreateRollbackServiceTest extends TestCase {
	public function testTouchesNothingWhenNoFileWasCreated(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Existing.pad', '', null);
	}

	/**
	 * The node itself, not its path and not a fresh lookup by id: this is
	 * the object the create made, in the request that made it.
	 */
	public function testDeletesTheNodeTheCreateMade(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$this->buildService()->rollbackFailedCreate('alice', '/Created.pad', '', $created);
	}

	/**
	 * A create that failed before writing anything still leaves a file. It
	 * has to go, or the name stays blocked and the user's retry is refused
	 * by the pre-check with no way to see why.
	 */
	public function testDeletesAFileNothingWasWrittenTo(): void {
		$empty = $this->createMock(File::class);
		$empty->method('getSize')->willReturn(0);
		$empty->expects($this->once())->method('delete');

		$this->buildService()->rollbackFailedCreate('alice', '/Created.pad', '', $empty);
	}

	public function testDeletesTheProvisionedPad(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('g.ABC$pad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', null);
	}

	public function testLeavesEtherpadAloneWithoutAPadId(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackFailedCreate('alice', '/Created.pad', '', null);
	}

	/** A cleanup failure must not replace the error that caused the rollback. */
	public function testReportsButSwallowsADeleteFailure(): void {
		$created = $this->createMock(File::class);
		$created->method('delete')->willThrowException(new \RuntimeException('nope'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Could not cleanup failed .pad file create'),
				$this->callback(static fn (array $c): bool => $c['file'] === '/Created.pad' && $c['uid'] === 'alice'),
			);

		$this->buildService(logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', '', $created);
	}

	public function testReportsButSwallowsAPadDeleteFailure(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('etherpad down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not cleanup failed Etherpad create'), $this->anything());

		$this->buildService(etherpad: $etherpad, logger: $logger)
			->rollbackFailedCreate('alice', '/Created.pad', 'g.ABC$pad', null);
	}

	/**
	 * For the flows that clean up their own pad. Routed through the
	 * file-only method rather than relying on an empty pad id to skip the
	 * pad step, so a future pad-side step cannot leak into it.
	 */
	public function testFileOnlyRollbackLeavesThePadAlone(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackCreatedFileOnly('alice', '/Created.pad', 'g.ABC$pad', $created);
	}

	/**
	 * An external create links a pad that already exists elsewhere, so the
	 * file goes and nothing is deleted on the Etherpad side.
	 */
	public function testExternalRollbackDeletesTheFileButNoPad(): void {
		$created = $this->createMock(File::class);
		$created->expects($this->once())->method('delete');

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildService(etherpad: $etherpad)
			->rollbackExternalCreate('alice', '/Created.pad', $created);
	}

	private function buildService(
		?EtherpadClient $etherpad = null,
		?LoggerInterface $logger = null,
	): PadCreateRollbackService {
		return new PadCreateRollbackService(
			$etherpad ?? $this->createMock(EtherpadClient::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
