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
use OCA\EtherpadNextcloud\Tests\Support\InMemoryBindingTable;
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

	/**
	 * The conditional writes are all that stands between two flows that
	 * each take a row for theirs, so what proves them is the rows they
	 * leave alone. File 2 names the pad the first call asks for: without
	 * the file in the statement that call takes file 2's row, without the
	 * pad it takes file 1's.
	 */
	public function testRebindMovesARowOnlyWhileItNamesThatPadInThatState(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(1, 'old', BindingService::STATE_PENDING_DELETE),
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		]);
		$service = new BindingService($table, new FixedClock(500), $this->createMock(LoggerInterface::class));
		$before = $table->rows;

		self::assertFalse($service->rebind(1, 'other', BindingService::STATE_PENDING_DELETE, 'new', BindingService::STATE_ACTIVE), 'another pad');
		self::assertFalse($service->rebind(1, 'old', BindingService::STATE_ACTIVE, 'new', BindingService::STATE_ACTIVE), 'another state');
		self::assertSame($before, $table->rows);

		self::assertTrue($service->rebind(1, 'old', BindingService::STATE_PENDING_DELETE, 'new', BindingService::STATE_ACTIVE));
		self::assertSame([
			['file_id' => 1, 'pad_id' => 'new', 'state' => BindingService::STATE_ACTIVE, 'deleted_at' => null, 'updated_at' => 500],
			self::bindingRow(2, 'other', BindingService::STATE_PENDING_DELETE),
		], $table->rows);
	}

	/**
	 * deleted_at says the file is in the trash. A restore that has to wait
	 * has its file back, so the date goes, whichever way the row got there.
	 */
	public function testARestoreLeftWaitingIsNotDatedAsDeleted(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_PENDING_DELETE)]);
		$service = new BindingService($table, new FixedClock(500), $this->createMock(LoggerInterface::class));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_PENDING_DELETE, BindingService::STATE_RESTORE_PENDING));

		self::assertNull($table->rows[0]['deleted_at']);
	}

	/** Back in the trash is a deletion owed again, dated from now. */
	public function testTransitionToPendingDeleteDatesTheDeletionAnew(): void {
		$table = new InMemoryBindingTable([self::bindingRow(1, 'pad', BindingService::STATE_RESTORE_PENDING)]);
		$service = new BindingService($table, new FixedClock(500), $this->createMock(LoggerInterface::class));

		self::assertTrue($service->transition(1, 'pad', BindingService::STATE_RESTORE_PENDING, BindingService::STATE_PENDING_DELETE));

		self::assertSame(
			['file_id' => 1, 'pad_id' => 'pad', 'state' => BindingService::STATE_PENDING_DELETE, 'deleted_at' => 500, 'updated_at' => 500],
			$table->rows[0],
		);
	}

	/** The same three conditions, for the recheck's delete of a row whose pad is gone. */
	public function testDeleteInStateRemovesARowOnlyWhileItNamesThatPadInThatState(): void {
		$table = new InMemoryBindingTable([
			self::bindingRow(1, 'old', BindingService::STATE_RESTORE_PENDING),
			self::bindingRow(2, 'other', BindingService::STATE_RESTORE_PENDING),
		]);
		$service = new BindingService($table, new FixedClock(500), $this->createMock(LoggerInterface::class));
		$before = $table->rows;

		self::assertFalse($service->deleteInState(1, 'other', BindingService::STATE_RESTORE_PENDING), 'another pad');
		self::assertFalse($service->deleteInState(1, 'old', BindingService::STATE_ACTIVE), 'another state');
		self::assertSame($before, $table->rows);

		self::assertTrue($service->deleteInState(1, 'old', BindingService::STATE_RESTORE_PENDING));
		self::assertSame([self::bindingRow(2, 'other', BindingService::STATE_RESTORE_PENDING)], $table->rows);
	}

	/**
	 * A sweep has to know where each owed deletion's file is now, and a row
	 * whose file is gone has no file cache row to join: left, not inner.
	 * Aged by when the trash recorded it.
	 */
	public function testOwedDeletionsComeWithTheirFilesPath(): void {
		$qb = new BindingServiceTestQueryBuilder([['file_id' => 10, 'pad_id' => 'pad-a', 'state' => BindingService::STATE_PENDING_DELETE, 'file_path' => null]]);
		$service = $this->buildServiceWithQueryBuilder($qb, 100000);

		$rows = $service->findPendingDeleteByAge(3600, 86400, 50);

		$this->assertNull($rows[0]['file_path']);
		$this->assertSame([['fc.path', 'file_path']], $qb->aliases);
		$this->assertSame([['b', 'filecache', 'fc', ['eq', 'b.file_id', 'fc.fileid']]], $qb->leftJoins);
		$this->assertSame([
			['eq', 'b.state', 'param1'],
			['lte', 'b.deleted_at', 'param2'],
			['gt', 'b.deleted_at', 'param3'],
		], $qb->conditions);
		$this->assertSame([96400, 13600], [$qb->parameters[1][1], $qb->parameters[2][1]]);
	}

	/**
	 * Unaged, there is no condition on the date at all, so a row that never
	 * had one is reached too.
	 */
	public function testAnUnagedSweepReachesOwedDeletionsWithoutADate(): void {
		$qb = new BindingServiceTestQueryBuilder([]);
		$this->buildServiceWithQueryBuilder($qb, 100000)->findPendingDeleteByAge(0, null, 50);

		$this->assertSame([['eq', 'b.state', 'param1']], $qb->conditions);
	}

	/** @return array<string,mixed> */
	private static function bindingRow(int $fileId, string $padId, string $state): array {
		return ['file_id' => $fileId, 'pad_id' => $padId, 'state' => $state, 'deleted_at' => 100, 'updated_at' => 100];
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

	/** @var list<array{string,string}> */
	public array $aliases = [];
	/** @var list<array{string,string,string,mixed}> */
	public array $leftJoins = [];

	public function select(string ...$select): self {
		return $this;
	}

	public function selectAlias(string $select, string $alias): self {
		$this->aliases[] = [$select, $alias];
		return $this;
	}

	public function from(string $table, ?string $alias = null): self {
		return $this;
	}

	public function leftJoin(string $fromAlias, string $join, string $alias, mixed $condition): self {
		$this->leftJoins[] = [$fromAlias, $join, $alias, $condition];
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
