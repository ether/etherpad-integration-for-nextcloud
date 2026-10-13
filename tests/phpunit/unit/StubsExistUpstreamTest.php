<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The unit suite runs against hand-written stubs, so a test against a stub
 * method Nextcloud renamed or dropped stays green on code that cannot run.
 * The check runs as a script of its own: it needs Composer's autoloader,
 * which the PHP 8.1 job's PHPUnit PHAR does not load.
 */
class StubsExistUpstreamTest extends TestCase {
	private ?string $fixtures = null;

	protected function tearDown(): void {
		if ($this->fixtures === null) {
			return;
		}
		foreach (['upstream', 'stubs'] as $side) {
			@unlink($this->fixtures . '/' . $side . '/Types.php');
			@rmdir($this->fixtures . '/' . $side);
		}
		@rmdir($this->fixtures);
	}

	public function testEveryStubMemberExistsInTheLockedPackages(): void {
		[$output, $status] = self::check();

		$this->assertSame([], $output);
		$this->assertSame(0, $status);
	}

	/** @param list<string> $findings */
	#[DataProvider('fixtures')]
	public function testJudgesAStubAgainstUpstream(string $upstream, string $stub, array $findings): void {
		$this->fixtures = sys_get_temp_dir() . '/stubs-' . bin2hex(random_bytes(6));
		foreach (['upstream' => $upstream, 'stubs' => $stub] as $side => $code) {
			mkdir($this->fixtures . '/' . $side, 0700, true);
			file_put_contents($this->fixtures . '/' . $side . '/Types.php', "<?php\nnamespace OCP;\n" . $code);
		}

		[$output, $status] = self::check($this->fixtures . '/stubs', $this->fixtures . '/upstream');

		$this->assertSame(
			array_map(fn (string $finding): string => str_replace('{dir}', (string)$this->fixtures, $finding), $findings),
			// The parser's own words differ between its versions.
			array_map(static fn (string $line): string => (string)preg_replace('/(: cannot be read: ).*/', '$1…', $line), $output),
		);
		$this->assertSame($findings === [] ? 0 : 1, $status);
	}

	/** @return array<string, array{string, string, list<string>}> */
	public static function fixtures(): array {
		return [
			'a method upstream has, with a looser signature' => [
				'interface IJobs { public function add(IJob|string $job, mixed $argument = null): void; }',
				'interface IJobs { public function add($job): void; }',
				[],
			],
			'a method upstream lacks' => [
				'interface IJobs { public function add($job): void; }',
				'interface IJobs { public function add($job): void; public function addLater($job): void; }',
				['OCP\IJobs::addLater(): no such method upstream'],
			],
			'a type upstream lacks' => [
				'',
				'interface IGone {}',
				['OCP\IGone: no such type upstream'],
			],
			'another kind of type' => [
				'class Job {}',
				'interface Job {}',
				['OCP\Job: an interface in the stub, a class upstream'],
			],
			// A catch for the parent holds against the stub, and not on Nextcloud.
			'a parent upstream does not have' => [
				'class NotFound extends \Exception {}',
				'class NotFound extends \RuntimeException {}',
				['OCP\NotFound: RuntimeException is no ancestor of it upstream'],
			],
			'a parent left out' => [
				'class Unavailable extends \RuntimeException {}',
				'class Unavailable extends \Exception {}',
				[],
			],
			'a method of a parent interface' => [
				'interface IBase { public function get(): string; } interface IJobs extends IBase {}',
				'interface IJobs { public function get(): string; }',
				[],
			],
			"a method of a parent class's trait" => [
				'trait Named { public function name(): string { return ""; } } abstract class Base { use Named; } class Job extends Base {}',
				'class Job { public function name(): string { return ""; } }',
				[],
			],
			'a trait method under another name' => [
				'trait Named { public function name(): string { return ""; } } class Job { use Named { name as title; } }',
				'class Job { public function title(): string { return ""; } }',
				[],
			],
			'a method the docblock promises' => [
				'/** @method string getTitle() */ class Entity { public function __call($name, $arguments) {} }',
				'class Entity { public function getTitle(): string { return ""; } }',
				[],
			],
			"a method of PHP's own interface" => [
				'interface IEntities extends \Countable {}',
				'interface IEntities { public function count(): int; }',
				[],
			],
			"a parent's private method" => [
				'class Base { private function hidden(): void {} } class Job extends Base {}',
				'class Job { public function hidden(): void {} }',
				['OCP\Job::hidden(): no such method upstream'],
			],
			'a method the stub makes static' => [
				'class Job { public function run(): void {} }',
				'class Job { public static function run(): void {} }',
				['OCP\Job::run(): static in the stub, not upstream'],
			],
			'a method the docblock promises, made static' => [
				'/** @method string getTitle() */ class Entity { public function __call($name, $arguments) {} }',
				'class Entity { public static function getTitle(): string { return ""; } }',
				['OCP\Entity::getTitle(): static in the stub, not upstream'],
			],
			'a trait method made protected under another name' => [
				'trait Named { public function name(): string { return ""; } } class Job { use Named { name as protected title; } }',
				'class Job { public function title(): string { return ""; } }',
				['OCP\Job::title(): public in the stub, protected upstream'],
			],
			'its own method over the one it inherits' => [
				'class Base { protected function run(): void {} } class Job extends Base { public function run(): void {} }',
				'class Job { public function run(): void {} }',
				[],
			],
			'a type declared twice upstream, either declaration counted' => [
				'if (PHP_VERSION_ID > 80000) { class Job { public function a(): void {} } } else { class Job { public function b(): void {} } }',
				'class Job { public function a(): void {} public function b(): void {} }',
				[],
			],
			'its own method over the trait one' => [
				'trait Runs { protected function run(): void {} } class Job { use Runs; public function run(): void {} }',
				'class Job { public function run(): void {} }',
				[],
			],
			'a trait method over the inherited one' => [
				'class Base { protected function run(): void {} } trait Runs { public function run(): void {} } class Job extends Base { use Runs; }',
				'class Job { public function run(): void {} }',
				[],
			],
			'a static method the docblock promises' => [
				'/** @method static Job create() */ class Job { public static function __callStatic($name, $arguments) {} }',
				'class Job { public static function create(): Job { return new Job(); } }',
				[],
			],
			'a method the stub makes public' => [
				'class Job { protected function run(): void {} }',
				'class Job { public function run(): void {} }',
				['OCP\Job::run(): public in the stub, protected upstream'],
			],
			'a constant of a parent, and one upstream lacks' => [
				'interface IBase { public const LEVEL = 1; } interface ILog extends IBase {}',
				'interface ILog { public const LEVEL = 1; public const GONE = 2; }',
				['OCP\ILog::GONE: no such constant upstream'],
			],
			'a backed enum, a pure one upstream' => [
				'enum Mode { case Read; }',
				'enum Mode: string { case Read = "r"; }',
				['OCP\Mode: BackedEnum is no ancestor of it upstream'],
			],
			'an enum case upstream lacks' => [
				'enum Mode: string { case Read = "r"; }',
				'enum Mode: string { case Read = "r"; case Write = "w"; }',
				['OCP\Mode::Write: no such constant upstream'],
			],
			// As most stubs are: declared only if nothing has loaded the real one.
			'a type declared inside a guard' => [
				'interface IJobs { public function add($job): void; }',
				'if (!interface_exists(IJobs::class)) { interface IJobs { public function add($job): void; public function drop(): void; } }',
				['OCP\IJobs::drop(): no such method upstream'],
			],
			'a type declared twice, each declaration checked' => [
				'class Job { public function run(): void {} }',
				'if (PHP_VERSION_ID > 80000) { class Job { public function gone(): void {} } } else { class Job { public function run(): void {} } }',
				['OCP\Job::gone(): no such method upstream'],
			],
			'a method only tests need' => [
				'class Server {}',
				'class Server { /** @stub-only Clears what a test registered. */ public static function reset(): void {} }',
				[],
			],
			'a method only tests need, which upstream has now' => [
				'class Server { public static function reset(): void {} }',
				'class Server { /** @stub-only Clears what a test registered. */ public static function reset(): void {} }',
				['OCP\Server::reset(): marked @stub-only, but upstream has it'],
			],
			'the tag named in a sentence only' => [
				'class Server {}',
				'class Server { /** Unlike reset(), not @stub-only. */ public static function clear(): void {} }',
				['OCP\Server::clear(): no such method upstream'],
			],
			'an ancestor that cannot be read' => [
				'interface ILog extends \Vendor\LoggerInterface {}',
				'interface ILog { public function log(): void; }',
				['OCP\ILog::log(): no such method upstream (Vendor\LoggerInterface could not be read)'],
			],
			'a method only tests need, beside an ancestor that cannot be read' => [
				'class Server extends \Vendor\Container {}',
				'class Server { /** @stub-only Clears what a test registered. */ public static function reset(): void {} }',
				['OCP\Server::reset(): marked @stub-only, but upstream may have it (Vendor\Container could not be read)'],
			],
			'a file that cannot be parsed' => [
				'class Job { public function }',
				'class Job {}',
				['{dir}/upstream/Types.php: cannot be read: …', 'OCP\Job: no such type upstream'],
			],
		];
	}

	/** @return array{list<string>, int} */
	private static function check(string ...$arguments): array {
		$output = [];
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Support/check-stubs.php')
				. ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
			$output,
			$status,
		);
		return [$output, $status];
	}
}
