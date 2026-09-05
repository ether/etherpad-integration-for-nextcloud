<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\BackfillPadMimeType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class BackfillPadMimeTypeTest extends TestCase {

	public function testSkipsWhenPadMimeTypeIsNotRegistered(): void {
		// getMimeId('application/x-etherpad-nextcloud') -> not found.
		$qb = new BackfillTestQueryBuilder([false]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains('not registered'));

		(new BackfillPadMimeType($this->connection($qb)))->run($output);

		$this->assertFalse($qb->executeStatementCalled);
	}

	public function testSkipsWhenApplicationMimePartIsMissing(): void {
		// pad mime found (7), application mimepart -> not found.
		$qb = new BackfillTestQueryBuilder([7, false]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains('application mimepart is missing'));

		(new BackfillPadMimeType($this->connection($qb)))->run($output);

		$this->assertFalse($qb->executeStatementCalled);
	}

	public function testRepairsTheMimePartOnRowsThatAlreadyCarryThePadType(): void {
		// pad mime id = 7, application mimepart id = 1, 3 rows updated.
		$qb = new BackfillTestQueryBuilder([7, 1], 3);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains('Backfilled MIME part for 3 .pad files.'));

		(new BackfillPadMimeType($this->connection($qb)))->run($output);

		$this->assertTrue($qb->executeStatementCalled);
		$this->assertSame('filecache', $qb->updateTable);

		$this->assertSame(['mimepart'], array_keys($qb->sets));
		$this->assertSame(1, $qb->params[$qb->sets['mimepart']]);

		$eq = $qb->findCondition('eq', 'mimetype');
		$this->assertNotNull($eq);
		$this->assertSame(7, $qb->params[$eq[2]]);
		$this->assertNull($qb->findCondition('like', 'name'), 'the name must not be matched here');

		$neq = $qb->findCondition('neq', 'mimepart');
		$this->assertNotNull($neq);
		$this->assertSame(1, $qb->params[$neq[2]]);

		$this->assertCount(2, $qb->conditions, 'the update must carry these two conditions and no other');

		foreach ([$qb->sets['mimepart'], $eq[2], $neq[2]] as $parameter) {
			$this->assertSame(
				IQueryBuilder::PARAM_INT,
				$qb->paramTypes[$parameter],
				'a mime id is a bigint column; binding it as a string casts the column and drops its index',
			);
		}
	}

	/**
	 * Zero updated rows has two very different causes, and the step is the
	 * only place that can tell them apart: everything is already correct, or
	 * nothing carries the type at all - which is what a reordered repair list
	 * would look like, since the mime-type step has to run first.
	 */
	public function testSaysSoWhenEveryPadFileWasAlreadyCorrect(): void {
		// pad mime 7, application mimepart 1, then one row carrying the type.
		$qb = new BackfillTestQueryBuilder([7, 1, 4242], 0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains('already correct'));

		(new BackfillPadMimeType($this->connection($qb)))->run($output);
	}

	public function testSaysSoWhenNoFileCarriesThePadTypeAtAll(): void {
		// pad mime 7, application mimepart 1, then no row carrying the type.
		$qb = new BackfillTestQueryBuilder([7, 1, false], 0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with($this->stringContains('No file carries the pad MIME type'));

		(new BackfillPadMimeType($this->connection($qb)))->run($output);
	}

	private function connection(BackfillTestQueryBuilder $qb): IDBConnection {
		return new class ($qb) implements IDBConnection {
			public function __construct(private BackfillTestQueryBuilder $qb) {
			}

			public function getQueryBuilder(): IQueryBuilder {
				return $this->qb;
			}
		};
	}
}

class BackfillTestQueryBuilder implements IQueryBuilder {
	/** @var array<string,mixed> */
	public array $params = [];
	/** @var array<string,int|null> */
	public array $paramTypes = [];
	/** @var array<string,string> field => parameter name */
	public array $sets = [];
	/** @var list<array<int,string>> */
	public array $conditions = [];
	public ?string $updateTable = null;
	public bool $executeStatementCalled = false;

	private int $counter = 0;

	/**
	 * @param list<int|false> $fetchOneQueue successive getMimeId() results
	 */
	public function __construct(private array $fetchOneQueue, private int $updateResult = 0) {
	}

	public function select(string $select): self {
		return $this;
	}

	public function from(string $table): self {
		return $this;
	}

	public function update(string $table): self {
		// The real getQueryBuilder() hands out a fresh builder per statement;
		// this double is reused, so a statement starts by forgetting the last.
		$this->conditions = [];
		$this->sets = [];
		$this->updateTable = $table;
		return $this;
	}

	public function set(string $field, string $parameter): self {
		$this->sets[$field] = $parameter;
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

	public function setMaxResults(int $maxResults): self {
		return $this;
	}

	public function createNamedParameter(mixed $value, ?int $type = null): string {
		$name = 'param' . ++$this->counter;
		$this->params[$name] = $value;
		$this->paramTypes[$name] = $type;
		return $name;
	}

	public function expr(): BackfillTestExpressionBuilder {
		return new BackfillTestExpressionBuilder();
	}

	public function executeQuery(): BackfillTestResult {
		$value = array_shift($this->fetchOneQueue);
		return new BackfillTestResult($value ?? false);
	}

	public function executeStatement(): int {
		$this->executeStatementCalled = true;
		return $this->updateResult;
	}

	/**
	 * @return array<int,string>|null the recorded [op, field, param] condition
	 */
	public function findCondition(string $op, string $field): ?array {
		foreach ($this->conditions as $condition) {
			if (($condition[0] ?? null) === $op && ($condition[1] ?? null) === $field) {
				return $condition;
			}
		}
		return null;
	}
}

class BackfillTestExpressionBuilder {
	/** @return array{string,string,string} */
	public function like(string $field, string $parameter): array {
		return ['like', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function neq(string $field, string $parameter): array {
		return ['neq', $field, $parameter];
	}

	/** @return array{string,string,string} */
	public function eq(string $field, string $parameter): array {
		return ['eq', $field, $parameter];
	}
}

class BackfillTestResult {
	public function __construct(private int|false $value) {
	}

	public function fetchOne(): int|false {
		return $this->value;
	}

	public function closeCursor(): void {
	}
}
