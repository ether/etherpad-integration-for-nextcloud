<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BindingServiceTest extends TestCase {
	public function testAssertConsistentMappingAcceptsActiveConsistentBinding(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 10,
			'pad_id' => 'pad-123',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$service->assertConsistentMapping(10, 'pad-123', BindingService::ACCESS_PUBLIC);
		$this->addToAssertionCount(1);
	}

	public function testAssertConsistentMappingRejectsPadIdMismatch(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 11,
			'pad_id' => 'pad-a',
			'access_mode' => BindingService::ACCESS_PROTECTED,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$this->expectException(BindingException::class);
		$this->expectExceptionMessage('Binding pad ID mismatch.');
		$service->assertConsistentMapping(11, 'pad-b', BindingService::ACCESS_PROTECTED);
	}

	public function testAssertConsistentMappingRejectsMissingBindingWithSpecificException(): void {
		$service = $this->buildServiceWithBinding(null);

		$this->expectException(MissingBindingException::class);
		$this->expectExceptionMessage('No binding exists for this file.');

		$service->assertConsistentMapping(10, 'pad-123', BindingService::ACCESS_PUBLIC);
	}

	public function testAssertConsistentMappingRejectsUnsupportedAccessMode(): void {
		$service = $this->buildServiceWithBinding([
			'file_id' => 12,
			'pad_id' => 'pad-a',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'state' => BindingService::STATE_ACTIVE,
		]);

		$this->expectException(BindingException::class);
		$this->expectExceptionMessage('Unsupported access mode: legacy');
		$service->assertConsistentMapping(12, 'pad-a', 'legacy');
	}

	public function testFindRestorePendingByAgeAddsUpperAndLowerAgeBounds(): void {
		$qb = new BindingServiceTestQueryBuilder([['file_id' => 10, 'pad_id' => 'pad-a']]);
		$service = $this->buildServiceWithQueryBuilder($qb, 100000);

		$rows = $service->findRestorePendingByAge(3600, 86400, 50);

		$this->assertSame([['file_id' => 10, 'pad_id' => 'pad-a']], $rows);
		$this->assertSame(50, $qb->maxResults);
		$this->assertContains(['lte', 'updated_at', 'param2'], $qb->conditions);
		$this->assertContains(['gt', 'updated_at', 'param3'], $qb->conditions);
		$this->assertSame([
			['param1', BindingService::STATE_RESTORE_PENDING, null],
			['param2', 96400, IQueryBuilder::PARAM_INT],
			['param3', 13600, IQueryBuilder::PARAM_INT],
		], $qb->parameters);
	}

	public function testFindRestorePendingByAgeOmitsUpperBoundForColdBucketAndClampsNegativeAge(): void {
		$qb = new BindingServiceTestQueryBuilder([]);
		$service = $this->buildServiceWithQueryBuilder($qb, 100000);

		$service->findRestorePendingByAge(-1, null, 0);

		$this->assertSame(1, $qb->maxResults);
		$this->assertContains(['lte', 'updated_at', 'param2'], $qb->conditions);
		$this->assertNotContains(['gt', 'updated_at', 'param3'], $qb->conditions);
		$this->assertSame([
			['param1', BindingService::STATE_RESTORE_PENDING, null],
			['param2', 100000, IQueryBuilder::PARAM_INT],
		], $qb->parameters);
	}

	/** @param array<string,mixed>|null $binding */
	private function buildServiceWithBinding(?array $binding): BindingService {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->buildTimeFactory(100000);

		return new class ($db, $timeFactory, $logger, $binding) extends BindingService {
			/** @param array<string,mixed>|null $binding */
			public function __construct(
				IDBConnection $db,
				ITimeFactory $timeFactory,
				LoggerInterface $logger,
				private ?array $binding,
			) {
				parent::__construct($db, $timeFactory, $logger);
			}

			public function findByFileId(int $fileId): ?array {
				return $this->binding;
			}
		};
	}

	private function buildServiceWithQueryBuilder(BindingServiceTestQueryBuilder $qb, int $now): BindingService {
		$db = new class ($qb) implements IDBConnection {
			public function __construct(private BindingServiceTestQueryBuilder $qb) {
			}

			public function getQueryBuilder(): IQueryBuilder {
				return $this->qb;
			}
		};

		return new BindingService($db, $this->buildTimeFactory($now), $this->createMock(LoggerInterface::class));
	}

	/**
	 * Both predicates and the state belong in the statement. Without the pad
	 * id a delete by file id alone takes the row a concurrent rebind just
	 * won; without `state` it takes the row a trash left as pending_delete,
	 * the only record of a deletion still owed.
	 */
	public function testDeletingAnActiveBindingNamesFileAndPadAndState(): void {
		$qb = new BindingServiceTestQueryBuilder([]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$service = new BindingService($db, $this->buildTimeFactory(0), $this->createMock(LoggerInterface::class));

		self::assertTrue($service->deleteActiveBinding(4711, 'nc-abc'));

		self::assertTrue($qb->deleted);
		self::assertSame(
			[['eq', 'file_id', 'param1'], ['eq', 'pad_id', 'param2'], ['eq', 'state', 'param3']],
			$qb->conditions,
		);
		self::assertSame(
			[4711, 'nc-abc', BindingService::STATE_ACTIVE],
			array_map(static fn (array $p): mixed => $p[1], $qb->parameters),
		);
	}

	/** No row matched: absent, another pad's, or pending_delete. */
	public function testDeletingAnActiveBindingReportsWhenNothingMatched(): void {
		$qb = new BindingServiceTestQueryBuilder([]);
		$qb->affectedRows = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$service = new BindingService($db, $this->buildTimeFactory(0), $this->createMock(LoggerInterface::class));

		self::assertFalse($service->deleteActiveBinding(4711, 'nc-abc'));
	}

	private function buildTimeFactory(int $now): ITimeFactory {
		return new FixedClock($now);
	}
}

class BindingServiceTestQueryBuilder implements IQueryBuilder {
	/** @var array<int,array{string,mixed,int|null}> */
	public array $parameters = [];
	/** @var array<int,array<int,string>> */
	public array $conditions = [];
	public int $maxResults = 0;
	private int $parameterCounter = 0;

	public int $affectedRows = 1;
	public bool $deleted = false;

	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(private array $rows) {
	}

	public function delete(string $table): self {
		$this->deleted = true;
		return $this;
	}

	public function executeStatement(): int {
		return $this->affectedRows;
	}

	public function select(string $select): self {
		return $this;
	}

	public function from(string $table): self {
		return $this;
	}

	public function where(mixed $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function andWhere(mixed $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function orderBy(string $field, string $direction): self {
		return $this;
	}

	public function setMaxResults(int $maxResults): self {
		$this->maxResults = $maxResults;
		return $this;
	}

	public function createNamedParameter(mixed $value, ?int $type = null): string {
		$name = 'param' . ++$this->parameterCounter;
		$this->parameters[] = [$name, $value, $type];
		return $name;
	}

	public function expr(): BindingServiceTestExpressionBuilder {
		return new BindingServiceTestExpressionBuilder();
	}

	public function executeQuery(): BindingServiceTestResult {
		return new BindingServiceTestResult($this->rows);
	}
}

class BindingServiceTestExpressionBuilder {
	/** @return array{string,string,string} */
	public function eq(string $field, string $parameter): array {
		return ['eq', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function lte(string $field, string $parameter): array {
		return ['lte', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function gt(string $field, string $parameter): array {
		return ['gt', $field, $parameter];
	}
}

class BindingServiceTestResult {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(private array $rows) {
	}

	/** @return array<int,array<string,mixed>> */
	public function fetchAll(): array {
		return $this->rows;
	}

	public function closeCursor(): void {
	}
}
