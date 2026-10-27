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
	 * names stays too: a pending_delete row may be the only record of it,
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

	/**
	 * A create's pad before its binding write is the create's beyond doubt:
	 * it goes without a question the binding table may not answer. Once the
	 * write began, the table is asked, and without an answer the pad stays.
	 */
	public function testACreatesPadIsAskedAboutOnlyOnceItsBindingWriteBegan(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->method('isBoundTo')->willThrowException(new \RuntimeException('database gone'));
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('nc-abc');

		$rollback = $this->buildRollback($binding, $etherpad);
		$rollback->discardCreated('nc-abc', null, 4711, 'create');
		$rollback->discardCreated('nc-abc', 4711, 4711, 'create');
	}

	/**
	 * Once a create's binding write began, a row naming its pad goes with
	 * the pad - createBinding can commit and still throw - while a row
	 * naming another pad belongs to whoever won the file, and stays.
	 */
	public function testACreatesRowGoesWithItsPadButAnotherPadsRowStays(): void {
		foreach ([true, false] as $namesThePad) {
			$binding = $this->createMock(BindingService::class);
			$binding->method('isBoundTo')->with(4711, 'nc-abc')->willReturn($namesThePad);
			$binding->expects($namesThePad ? $this->once() : $this->never())->method('deleteActiveBinding')->with(4711, 'nc-abc')->willReturn(true);
			$etherpad = $this->createMock(EtherpadClient::class);
			$etherpad->expects($this->once())->method('deletePad')->with('nc-abc');

			$this->buildRollback($binding, $etherpad)->discardCreated('nc-abc', 4711, 4711, 'create');
		}
	}

	/** A pad a create cannot take down is named with its file and way: nothing else leads to it. */
	public function testACreatesPadLeftOverIsNamedWithItsFileAndWay(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('etherpad down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not cleanup failed Etherpad create', $this->callback(static fn (array $context): bool => array_diff_key($context, ['error_origin' => true]) === [
			'app' => 'etherpad_nextcloud',
			'fileId' => 4711,
			'padId' => 'nc-abc',
			'operation' => 'template materialization',
			'error' => \RuntimeException::class,
			'error_message' => 'etherpad down',
		]));

		(new ProvisionedPadRollback($this->createMock(BindingService::class), new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)), $logger))
			->discardCreated('nc-abc', null, 4711, 'template materialization');
	}

	private function buildRollback(BindingService $binding, EtherpadClient $etherpad): ProvisionedPadRollback {
		$logger = $this->createMock(LoggerInterface::class);
		return new ProvisionedPadRollback($binding, new ManagedPadLifecycle($etherpad, $logger), $logger);
	}
}
