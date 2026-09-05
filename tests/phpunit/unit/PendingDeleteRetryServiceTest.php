<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ManagedPadLifecycle;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingDeleteRetryServiceTest extends TestCase {
	public function testRetryByAgeUsesAgeScopedRows(): void {
		$binding = $this->buildBindingService([
			['file_id' => 10, 'pad_id' => 'pad-a'],
			['file_id' => 11, 'pad_id' => 'pad-b'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$deletedPads = [];
		$etherpad->expects($this->exactly(2))
			->method('deletePad')
			->willReturnCallback(static function (string $padId) use (&$deletedPads): void {
				$deletedPads[] = $padId;
			});

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(3600, 86400, 50);

		$this->assertSame([3600, 86400, 50], $binding->lastAgeQuery);
		$this->assertSame(['pad-a', 'pad-b'], $deletedPads);
		$this->assertSame([
			'attempted' => 2,
			'resolved' => 2,
			'failed' => 0,
			'remaining' => 0,
		], $result);
	}

	public function testAlreadyDeletedPadResolvesPendingBinding(): void {
		$binding = $this->buildBindingService([
			['file_id' => 12, 'pad_id' => 'pad-gone'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('padID does not exist'));

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(86400, null, 10);

		$this->assertSame(1, $binding->deletedBindings);
		$this->assertSame(1, $result['resolved']);
		$this->assertSame(0, $result['failed']);
	}

	/**
	 * A protected pad is removed by its group, and Etherpad answers a group
	 * that is already gone with a different message than a pad that is —
	 * measured against 2.7.3 and 3.3.3. Without that spelling, a delete whose
	 * response was lost would sit in pending_delete and fail every retry for
	 * good.
	 */
	public function testAlreadyDeletedGroupResolvesPendingBinding(): void {
		$binding = $this->buildBindingService([
			['file_id' => 12, 'pad_id' => 'g.ABCDEFGHIJKLMNOP$p-gone'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		// The group is gone, so listing it is what reports that — and a pad
		// inside a group that does not exist cannot exist either.
		$etherpad->expects($this->once())
			->method('listPads')
			->with('g.ABCDEFGHIJKLMNOP')
			->willThrowException(new \RuntimeException('groupID does not exist'));
		$etherpad->expects($this->never())->method('deleteGroup');

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(86400, null, 10);

		$this->assertSame(1, $binding->deletedBindings);
		$this->assertSame(1, $result['resolved']);
		$this->assertSame(0, $result['failed']);
	}

	public function testUnclassifiedEtherpadErrorIsCountedAsFailureAndKeepsBinding(): void {
		// Transient errors (network blip, 5xx, etc.) must NOT delete the binding
		// row — the row stays in pending_delete so the next retry job picks it
		// up. Only "pad already deleted" is treated as resolved.
		$binding = $this->buildBindingService([
			['file_id' => 13, 'pad_id' => 'pad-down'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->method('deletePad')->willThrowException(new \RuntimeException('connection refused'));

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(0, 3600, 10);

		$this->assertSame(0, $binding->deletedBindings);
		$this->assertSame([
			'attempted' => 1,
			'resolved' => 0,
			'failed' => 1,
			'remaining' => 0,
		], $result);
	}

	public function testRowsWithMissingFileIdOrPadIdAreSkippedSilently(): void {
		// Defensive: malformed rows should be ignored, not counted as attempts.
		$binding = $this->buildBindingService([
			['file_id' => 0,  'pad_id' => 'pad-a'],   // invalid file_id
			['file_id' => 14, 'pad_id' => ''],         // invalid pad_id
			['file_id' => 15, 'pad_id' => 'pad-good'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('pad-good');

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(0, null, 10);

		$this->assertSame(1, $result['attempted']);
		$this->assertSame(1, $result['resolved']);
	}

	public function testEmptyResultReturnsAllZeroes(): void {
		$binding = $this->buildBindingService([]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->never())->method('deletePad');

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retryByAge(0, 3600, 10);

		$this->assertSame([
			'attempted' => 0,
			'resolved' => 0,
			'failed' => 0,
			'remaining' => 0,
		], $result);
	}

	public function testRetryWithoutAgeFilterProcessesAllPendingDeletes(): void {
		// retry() is the no-age-filter entry point used by the admin "retry
		// pending deletes" action (AdminController); it processes every
		// PENDING_DELETE row up to the limit.
		$binding = $this->buildBindingServiceWithStateRows([
			['file_id' => 16, 'pad_id' => 'pad-pending'],
		]);
		$etherpad = $this->createMock(EtherpadClient::class);
		$etherpad->expects($this->once())->method('deletePad')->with('pad-pending');

		$result = (new PendingDeleteRetryService(
			$binding,
			new ManagedPadLifecycle($etherpad, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		))->retry(50);

		$this->assertSame(50, $binding->lastStateLimit);
		$this->assertSame(1, $result['attempted']);
		$this->assertSame(1, $result['resolved']);
	}

	/** @param array<int,array<string,mixed>> $ageRows */
	private function buildBindingService(array $ageRows): BindingService {
		return new class (
			$this->createMock(IDBConnection::class),
			new FixedClock(),
			$this->createMock(LoggerInterface::class),
			$ageRows,
		) extends BindingService {
			/** @var array{int,int|null,int}|null */
			public ?array $lastAgeQuery = null;
			public int $deletedBindings = 0;

			/** @param array<int,array<string,mixed>> $ageRows */
			public function __construct(
				IDBConnection $db,
				ITimeFactory $timeFactory,
				LoggerInterface $logger,
				private array $ageRows,
			) {
				parent::__construct($db, $timeFactory, $logger);
			}

			public function findPendingDeleteByAge(int $minAgeSeconds, ?int $maxAgeSeconds, int $limit = 100): array {
				$this->lastAgeQuery = [$minAgeSeconds, $maxAgeSeconds, $limit];
				return $this->ageRows;
			}

			public function deletePendingDeleteBinding(int $fileId, string $padId): bool {
				$this->deletedBindings++;
				return true;
			}

			public function countByState(string $state): int {
				return 0;
			}
		};
	}

	/** @param array<int,array<string,mixed>> $stateRows */
	private function buildBindingServiceWithStateRows(array $stateRows): BindingService {
		return new class (
			$this->createMock(IDBConnection::class),
			new FixedClock(),
			$this->createMock(LoggerInterface::class),
			$stateRows,
		) extends BindingService {
			public int $lastStateLimit = 0;

			/** @param array<int,array<string,mixed>> $stateRows */
			public function __construct(
				IDBConnection $db,
				ITimeFactory $timeFactory,
				LoggerInterface $logger,
				private array $stateRows,
			) {
				parent::__construct($db, $timeFactory, $logger);
			}

			public function findByState(string $state, int $limit = 100): array {
				$this->lastStateLimit = $limit;
				return $this->stateRows;
			}

			public function deletePendingDeleteBinding(int $fileId, string $padId): bool {
				return true;
			}

			public function countByState(string $state): int {
				return 0;
			}
		};
	}

}
