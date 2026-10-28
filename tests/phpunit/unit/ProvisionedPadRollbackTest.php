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
 * Fail-closed where a row may name the pad: without an answer about the
 * binding, the pad stays. A pad a row may still name is reachable; one
 * taken away on a guess is not, and nothing retries a rollback.
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

	public function testACreatesPadGoesWithoutAQuestionToTheBindingTable(): void {
		$binding = $this->createMock(BindingService::class);
		$binding->expects($this->never())->method('isBoundTo');
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('nc-abc');

		$this->buildRollback($binding, $etherpad)->discardCreated(4711, 'nc-abc', 'create');
	}

	/** A row naming another pad belongs to whoever won the file. */
	public function testACreatesRowGoesWithItsPadButAnotherPadsRowStays(): void {
		foreach ([true, false] as $namesThePad) {
			$binding = $this->createMock(BindingService::class);
			$binding->method('isBoundTo')->with(4711, 'nc-abc')->willReturn($namesThePad);
			$binding->expects($namesThePad ? $this->once() : $this->never())->method('deleteActiveBinding')->with(4711, 'nc-abc')->willReturn(true);
			$etherpad = $this->createMock(EtherpadClient::class);
			$etherpad->expects($this->once())->method('deletePad')->with('nc-abc');

			$this->buildRollback($binding, $etherpad)->removeMatchingBindingAndDiscard(4711, 'nc-abc', 'create');
		}
	}

	/** Before its row or after it is gone, a pad left over is named with its file and way. */
	public function testAPadLeftOverIsNamedWithItsFileAndWay(): void {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('etherpad down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('Could not remove a new Etherpad pad; it is left over.', $this->callback(static fn (array $context): bool => array_diff_key($context, ['error_origin' => true]) === [
			'app' => 'etherpad_nextcloud',
			'padId' => 'nc-abc',
			'error' => \RuntimeException::class,
			'error_message' => 'etherpad down',
			'fileId' => 4711,
			'operation' => 'template materialization',
		]));
		$rollback = new ProvisionedPadRollback($this->createMock(BindingService::class), new ManagedPadLifecycle($etherpad, $logger), $logger);

		$rollback->discardCreated(4711, 'nc-abc', 'template materialization');
		$rollback->removeMatchingBindingAndDiscard(4711, 'nc-abc', 'template materialization');
	}

	private function buildRollback(BindingService $binding, EtherpadClient $etherpad): ProvisionedPadRollback {
		$logger = $this->createMock(LoggerInterface::class);
		return new ProvisionedPadRollback($binding, new ManagedPadLifecycle($etherpad, $logger), $logger);
	}
}
