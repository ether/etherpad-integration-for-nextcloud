<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\RestoreRecheckService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RestoreRecheckServiceTest extends TestCase {
	/**
	 * Each row goes by Etherpad's own answer for its pad, and no answer
	 * changes nothing. A row that fails to settle does not stop the rest.
	 */
	public function testRecheckByAgeSettlesEachRowByWhatEtherpadSays(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findRestorePendingByAge')
			->with(3600, 86400, 50)
			->willReturn([
				['file_id' => 9, 'pad_id' => 'pad-db-down'],
				['file_id' => 10, 'pad_id' => 'pad-there'],
				['file_id' => 11, 'pad_id' => 'pad-gone'],
				['file_id' => 12, 'pad_id' => 'pad-unknown'],
			]);
		$transitions = [];
		$bindingService->method('transition')->willReturnCallback(static function (mixed ...$args) use (&$transitions): bool {
			if ($args[1] === 'pad-db-down') {
				throw new \RuntimeException('database went away');
			}
			$transitions[] = $args;
			return true;
		});
		$bindingService->expects($this->once())
			->method('deleteInState')
			->with(11, 'pad-gone', BindingService::STATE_RESTORE_PENDING)
			->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Could not settle a restore that was left undecided.', $this->anything());

		$this->buildService($bindingService, $this->buildEtherpad(), $logger)->recheckByAge(3600, 86400, 50);

		$this->assertSame([[10, 'pad-there', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_ACTIVE]], $transitions);
	}

	/** The admin panel shows what a run did and what is left. */
	public function testRecheckReportsWhatItSettled(): void {
		$bindingService = $this->createMock(BindingService::class);
		$bindingService->expects($this->once())
			->method('findByState')
			->with(BindingService::STATE_RESTORE_PENDING, 50)
			->willReturn([
				['file_id' => 0, 'pad_id' => 'pad-there'],
				['file_id' => 10, 'pad_id' => 'pad-there'],
				['file_id' => 12, 'pad_id' => 'pad-unknown'],
			]);
		$bindingService->method('transition')->willReturn(true);
		$bindingService->method('countByState')->with(BindingService::STATE_RESTORE_PENDING)->willReturn(1);

		$result = $this->buildService($bindingService, $this->buildEtherpad())->recheck(50);

		$this->assertSame(['checked' => 2, 'settled' => 1, 'remaining' => 1], $result);
	}

	/** An Etherpad that has pad-there, has no pad-gone, and cannot answer for pad-unknown. Nothing may be deleted. */
	private function buildEtherpad(): EtherpadClient&MockObject {
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('getRevisionsCount')->willReturnCallback(static function (string $padId): int {
			return match ($padId) {
				'pad-gone' => throw new \RuntimeException('padID does not exist'),
				'pad-unknown' => throw new \RuntimeException('connection refused'),
				default => 3,
			};
		});
		$etherpad->expects($this->never())->method('deletePad');
		$etherpad->expects($this->never())->method('deleteGroup');
		return $etherpad;
	}

	private function buildService(BindingService $bindingService, EtherpadClient $etherpad, ?LoggerInterface $logger = null): RestoreRecheckService {
		return new RestoreRecheckService(
			$bindingService,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}
}
