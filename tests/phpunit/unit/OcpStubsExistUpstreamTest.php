<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The unit suite runs against hand-written OCP stubs, so a test against a
 * stub method Nextcloud renamed or dropped stays green on code that cannot
 * run. The check reads upstream in a process of its own: its classes carry
 * the stubs' names.
 */
class OcpStubsExistUpstreamTest extends TestCase {
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

	public function testEveryStubMemberExistsInTheLockedOcp(): void {
		[$output, $status] = self::check();

		$this->assertSame([], $output);
		$this->assertSame(0, $status);
	}

	/** @param list<string> $findings */
	#[DataProvider('fixtures')]
	public function testJudgesAStubAgainstUpstream(string $upstream, string $stub, array $findings): void {
		$this->fixtures = sys_get_temp_dir() . '/ocp-stubs-' . bin2hex(random_bytes(6));
		foreach (['upstream' => $upstream, 'stubs' => $stub] as $side => $code) {
			mkdir($this->fixtures . '/' . $side, 0700, true);
			file_put_contents($this->fixtures . '/' . $side . '/Types.php', "<?php\nnamespace OCP;\n" . $code);
		}

		[$output, $status] = self::check($this->fixtures . '/upstream', $this->fixtures . '/stubs');

		$this->assertSame($findings, $output);
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
			"a method of PHP's own interface" => [
				'interface IEntities extends \Countable {}',
				'interface IEntities { public function count(): int; }',
				[],
			],
			'a constant of a parent, and one upstream lacks' => [
				'interface IBase { public const LEVEL = 1; } interface ILog extends IBase {}',
				'interface ILog { public const LEVEL = 1; public const GONE = 2; }',
				['OCP\ILog::GONE: no such constant upstream'],
			],
			'an enum case upstream lacks' => [
				'enum Mode: string { case Read = "r"; }',
				'enum Mode: string { case Read = "r"; case Write = "w"; }',
				['OCP\Mode::Write: no such constant upstream'],
			],
			// As every stub is: declared only if nothing has loaded the real one.
			'a type declared inside a guard' => [
				'interface IJobs { public function add($job): void; }',
				'if (!interface_exists(IJobs::class)) { interface IJobs { public function add($job): void; public function drop(): void; } }',
				['OCP\IJobs::drop(): no such method upstream'],
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
			'an ancestor that cannot be read' => [
				'interface ILog extends \Vendor\LoggerInterface {}',
				'interface ILog { public function log(): void; }',
				['OCP\ILog::log(): no such method upstream (Vendor\LoggerInterface could not be read)'],
			],
		];
	}

	/** @return array{list<string>, int} */
	private static function check(string ...$arguments): array {
		$output = [];
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Support/check-ocp-stubs.php')
				. ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
			$output,
			$status,
		);
		return [$output, $status];
	}
}
