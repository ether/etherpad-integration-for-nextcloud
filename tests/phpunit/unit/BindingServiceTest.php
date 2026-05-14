<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
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

	public function testFindPendingDeleteByAgeAddsUpperAndLowerAgeBounds(): void {
		$qb = new BindingServiceTestQueryBuilder([['file_id' => 10, 'pad_id' => 'pad-a']]);
		$service = $this->buildServiceWithQueryBuilder($qb, 100000);

		$rows = $service->findPendingDeleteByAge(3600, 86400, 50);

		$this->assertSame([['file_id' => 10, 'pad_id' => 'pad-a']], $rows);
		$this->assertSame(50, $qb->maxResults);
		$this->assertContains(['lte', 'deleted_at', 'param2'], $qb->conditions);
		$this->assertContains(['gt', 'deleted_at', 'param3'], $qb->conditions);
		$this->assertSame([
			['param1', BindingService::STATE_PENDING_DELETE, null],
			['param2', 96400, IQueryBuilder::PARAM_INT],
			['param3', 13600, IQueryBuilder::PARAM_INT],
		], $qb->parameters);
	}

	public function testFindPendingDeleteByAgeOmitsUpperBoundForColdBucketAndClampsNegativeAge(): void {
		$qb = new BindingServiceTestQueryBuilder([]);
		$service = $this->buildServiceWithQueryBuilder($qb, 100000);

		$service->findPendingDeleteByAge(-1, null, 0);

		$this->assertSame(1, $qb->maxResults);
		$this->assertContains(['lte', 'deleted_at', 'param2'], $qb->conditions);
		$this->assertNotContains(['gt', 'deleted_at', 'param3'], $qb->conditions);
		$this->assertSame([
			['param1', BindingService::STATE_PENDING_DELETE, null],
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

	private function buildTimeFactory(int $now): ITimeFactory {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($now);
		return $timeFactory;
	}
}

class BindingServiceTestQueryBuilder implements IQueryBuilder {
	/** @var array<int,array{string,mixed,int|null}> */
	public array $parameters = [];
	/** @var array<int,array<int,string>> */
	public array $conditions = [];
	public int $maxResults = 0;
	private int $parameterCounter = 0;

	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(private array $rows) {
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

	/** @return array{string,string} */
	public function isNotNull(string $field): array {
		return ['isNotNull', $field];
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
