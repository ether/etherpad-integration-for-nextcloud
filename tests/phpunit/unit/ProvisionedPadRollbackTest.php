<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\ProvisionedPadRollback;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rule these hold is fail-closed: without an answer about the binding,
 * the pad stays. A pad a row may still name is reachable; one taken away
 * on a guess is not, and nothing retries a rollback.
 */
class ProvisionedPadRollbackTest extends TestCase {
	public function testKeepsThePadWhenTheBindingCannotBeRead(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->method('isBoundTo')->willThrowException(new \RuntimeException('database gone'));

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');
		$etherpad->expects($this->never())->method('deleteGroup');

		$this->buildRollback($binding, $etherpad)
			->removeMatchingBindingAndDiscard(4711, 'nc-abc', 'create');
	}

	public function testKeepsThePadWhenTheBindingCannotBeRemoved(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->method('isBoundTo')->willReturn(true);
		$binding->method('deleteActiveBinding')->willThrowException(new \RuntimeException('database gone'));

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildRollback($binding, $etherpad)
			->removeMatchingBindingAndDiscard(4711, 'nc-abc', 'create');
	}

	/** The same rule on the other method, which never removes a row. */
	public function testKeepsThePadWhenTheBindingCannotBeReadWhileDiscardingUnbound(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->method('isBoundTo')->willThrowException(new \RuntimeException('database gone'));

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$this->buildRollback($binding, $etherpad)
			->discardUnlessBoundToFile(4711, 'nc-abc', 'first init');
	}

	/**
	 * The row is no longer this attempt's to remove - the file was rebound
	 * between the read and the delete, or a trash that could not reach
	 * Etherpad left the row pending_delete. Either way the pad it still
	 * names stays too, so PendingDeleteRetryService has something to retry
	 * and a rival's file keeps its mapping.
	 */
	public function testLeavesThePadWhenTheRowIsNoLongerItsToRemove(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->method('isBoundTo')->willReturn(true);
		$binding->method('deleteActiveBinding')->willReturn(false);

		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');
		$etherpad->expects($this->never())->method('deleteGroup');

		$this->buildRollback($binding, $etherpad)
			->removeMatchingBindingAndDiscard(4711, 'nc-abc', 'create');
	}

	private function buildRollback(BindingService $binding, EtherpadClient $etherpad): ProvisionedPadRollback {
		$logger = $this->createMock(LoggerInterface::class);
		return new ProvisionedPadRollback($binding, new ManagedPadLifecycle($etherpad, $logger), $logger);
	}
}
