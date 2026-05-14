<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\PendingDeleteRetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
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
			$etherpad,
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
			$etherpad,
			$this->createMock(LoggerInterface::class),
		))->retryByAge(86400, null, 10);

		$this->assertSame(1, $binding->deletedBindings);
		$this->assertSame(1, $result['resolved']);
		$this->assertSame(0, $result['failed']);
	}

	/** @param array<int,array<string,mixed>> $ageRows */
	private function buildBindingService(array $ageRows): BindingService {
		return new class (
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
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
}
