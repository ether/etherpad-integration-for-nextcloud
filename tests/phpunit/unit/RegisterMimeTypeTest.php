<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\RegisterMimeType;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RegisterMimeTypeTest extends TestCase {
	private string $root;
	private string $configDir;
	private string $appDir;
	private mixed $previousServerRoot;
	private mixed $previousConfigDir;
	private RegisterMimeTypeTestDb $db;
	/** pad_files_typed, as the app config would keep it */
	private bool $typed = false;

	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/etherpad-nextcloud-mime-' . bin2hex(random_bytes(8));
		$this->configDir = $this->root . '/config';
		$this->appDir = $this->root . '/custom_apps/etherpad_nextcloud';

		mkdir($this->configDir, 0777, true);
		mkdir($this->appDir . '/img/filetypes', 0777, true);
		mkdir($this->root . '/core/img/filetypes', 0777, true);
		file_put_contents(
			$this->appDir . '/img/filetypes/etherpad-nextcloud-pad.svg',
			'<svg>pad</svg>',
		);

		$this->previousServerRoot = \OC::$SERVERROOT;
		$this->previousConfigDir = \OC::$configDir;
		\OC::$SERVERROOT = $this->root;
		\OC::$configDir = $this->configDir;
		$this->db = new RegisterMimeTypeTestDb();
	}

	protected function tearDown(): void {
		\OC::$SERVERROOT = $this->previousServerRoot;
		\OC::$configDir = $this->previousConfigDir;
		$this->removeDirectory($this->root);

		parent::tearDown();
	}

	public function testRegistersTheCompleteMimeConfigurationOnAFirstRun(): void {
		$output = new RegisterMimeTypeTestOutput();
		$this->step()->run($output);

		$this->assertSame(
			[PadFileType::MIME],
			$this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION],
		);
		$this->assertSame(
			'text',
			$this->jsonFile('mimetypealiases.json')[PadFileType::MIME],
		);
		$this->assertSame(
			'Etherpad',
			$this->jsonFile('mimetypenames.json')[PadFileType::MIME],
		);
		// Core is signed; nothing of ours belongs in it.
		$this->assertFileDoesNotExist($this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg');
		$this->assertCount(1, $this->db->statements);
		$this->assertSame([], $output->warnings);
		$this->assertContains('Gave 3 .pad files their MIME type.', $output->infos);
		$this->assertContains('Registered .pad files.', $output->infos);
	}

	/** One statement sets the type and its part, on the files that lack either. */
	public function testTypesThePadFilesInOneStatement(): void {
		$this->step()->run(new RegisterMimeTypeTestOutput());

		$statement = $this->db->statements[0];
		$this->assertSame('filecache', $statement->table);
		$this->assertSame(['mimetype' => 42, 'mimepart' => 7], $statement->sets);
		$this->assertSame([
			"LOWER(name) LIKE '%.pad'",
			'mimetype <> 2',
			'(mimetype <> 42 OR mimepart <> 7)',
		], $statement->conditions);
	}

	/**
	 * The scan reads the whole file cache, so it runs until one has finished
	 * with the mapping in place: on a first install the mapping comes with
	 * the scan, and a file added meanwhile came in untyped.
	 */
	public function testScansUntilAScanWithTheMappingInPlaceFinished(): void {
		$output = new RegisterMimeTypeTestOutput();
		$this->step()->run($output);
		$this->assertSame([1, false], [count($this->db->statements), $this->typed], 'a first install');

		$this->step()->run(new RegisterMimeTypeTestOutput());
		$this->assertSame([2, true], [count($this->db->statements), $this->typed], 'the first update');

		$output = new RegisterMimeTypeTestOutput();
		$this->step()->run($output);
		$this->assertSame([2, true], [count($this->db->statements), $this->typed], 'every update after it');
		$this->assertContains('The .pad files were typed already; the file cache was not scanned. To scan it again, delete the app config value pad_files_typed and run the repair.', $output->infos);
	}

	/** Without the mapping, files come in untyped: it is scanned for again. */
	public function testScansAgainWhereTheMappingIsMissingOrAnotherAppsAndForgetsItWasDone(): void {
		foreach ([
			'mapped to another type' => ['pad' => ['application/x-ownpad']],
			'mapping file lost' => null,
		] as $case => $mapping) {
			@unlink($this->configDir . '/mimetypemapping.json');
			if ($mapping !== null) {
				$this->writeJson('mimetypemapping.json', $mapping);
			}
			$this->db = new RegisterMimeTypeTestDb();
			$this->typed = true;

			$this->step()->run(new RegisterMimeTypeTestOutput());

			$this->assertCount(1, $this->db->statements, $case);
			$this->assertFalse($this->typed, $case);
			$this->assertSame([PadFileType::MIME], $this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION], $case);
		}
	}

	/**
	 * A repair that fails, on a lock timeout say, does not keep the mapping
	 * from being written, and is not taken for one that finished.
	 */
	public function testAFailedRepairStillWritesTheMappingAndIsTriedAgain(): void {
		$this->writeJson('mimetypemapping.json', [PadFileType::EXTENSION => [PadFileType::MIME]]);
		$this->db->failure = new \RuntimeException('lock wait timeout');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertFalse($this->typed);
		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('Could not give the .pad files their MIME type', $output->warnings[0]);
		$this->assertStringContainsString('lock wait timeout', $output->warnings[0]);
		$this->assertSame('text', $this->jsonFile('mimetypealiases.json')[PadFileType::MIME]);
		$this->assertSame('Etherpad', $this->jsonFile('mimetypenames.json')[PadFileType::MIME]);
	}

	public function testPreservesUnrelatedConfigurationAndIsIdempotent(): void {
		$this->writeJson('mimetypemapping.json', [
			'txt' => ['text/plain'],
			PadFileType::EXTENSION => ['application/octet-stream', 'text/plain'],
		]);
		$this->writeJson('mimetypealiases.json', [
			'text/markdown' => 'text',
		]);
		$this->writeJson('mimetypenames.json', [
			'text/markdown' => 'Markdown document',
		]);

		$step = $this->step();
		$step->run(new RegisterMimeTypeTestOutput());
		$afterFirstRun = $this->configContents();
		$step->run(new RegisterMimeTypeTestOutput());

		$this->assertSame($afterFirstRun, $this->configContents());
		$this->assertSame(['text/plain'], $this->jsonFile('mimetypemapping.json')['txt']);
		$this->assertSame(
			[PadFileType::MIME],
			$this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION],
		);
		$this->assertSame('text', $this->jsonFile('mimetypealiases.json')['text/markdown']);
		$this->assertSame(
			'Markdown document',
			$this->jsonFile('mimetypenames.json')['text/markdown'],
		);
	}

	public function testRejectsInvalidRequiredConfigurationWithoutReportingSuccess(): void {
		file_put_contents($this->configDir . '/mimetypemapping.json', '{invalid');
		$this->typed = true;
		$output = new RegisterMimeTypeTestOutput();

		try {
			$this->step()->run($output);
			self::fail('Invalid required MIME configuration must abort registration.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('contains invalid JSON', $e->getMessage());
		}

		// Scanned for, as a file that cannot be read maps nothing.
		$this->assertCount(1, $this->db->statements);
		$this->assertFalse($this->typed);
		$this->assertNotContains('Registered .pad files.', $output->infos);
	}

	public function testAlreadyCorrectReadOnlyConfigurationSucceeds(): void {
		$this->writeJson('mimetypemapping.json', [
			PadFileType::EXTENSION => [PadFileType::MIME],
		]);
		$this->writeJson('mimetypealiases.json', [
			PadFileType::MIME => 'text',
		]);
		$this->writeJson('mimetypenames.json', [
			PadFileType::MIME => 'Etherpad',
		]);

		$before = $this->configContents();
		foreach (glob($this->configDir . '/*.json') ?: [] as $file) {
			chmod($file, 0444);
		}
		chmod($this->configDir, 0555);

		$output = new RegisterMimeTypeTestOutput();
		$this->step()->run($output);

		// The permissions alone would prove nothing as root, where chmod is
		// not enforced. What has to hold either way is that nothing was
		// rewritten, because every mapping was already there.
		$this->assertSame($before, $this->configContents());
		$this->assertSame([], $output->warnings);
		$this->assertContains('Registered .pad files.', $output->infos);
	}

	/** What an interrupted write leaves behind; the step has to heal it, not refuse it. */
	public function testHealsATruncatedMappingFile(): void {
		file_put_contents($this->configDir . '/mimetypemapping.json', '');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertSame([PadFileType::MIME], $this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION]);
		$this->assertSame([], $output->warnings);
	}

	public function testARequiredWriteFailureAbortsRegistration(): void {
		\OC::$configDir = '/dev/null';
		$output = new RegisterMimeTypeTestOutput();

		try {
			$this->step()->run($output);
			self::fail('A failed required write must abort registration.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Could not write MIME configuration file', $e->getMessage());
		}

		// The file cache is repaired first, so an unwritable config
		// directory does not keep existing files from opening.
		$this->assertCount(1, $this->db->statements);
		$this->assertNotContains('Registered .pad files.', $output->infos);
	}

	public function testRejectsAJsonListWhereAMappingObjectIsRequired(): void {
		file_put_contents($this->configDir . '/mimetypemapping.json', "[\"pad\"]\n");

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('must contain a JSON object');

		$this->step()->run(new RegisterMimeTypeTestOutput());
	}

	/** Empty is empty: `[]` and `{}` decode alike and both mean no mappings yet. */
	public function testAcceptsAnEmptyMappingFile(): void {
		file_put_contents($this->configDir . '/mimetypemapping.json', "[]\n");
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertSame([PadFileType::MIME], $this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION]);
		$this->assertSame([], $output->warnings);
	}

	/** An admin who points one of these at a managed file means the file, not the link. */
	public function testWritesThroughASymlinkedConfigurationFile(): void {
		$managed = $this->root . '/managed/mimetypemapping.json';
		mkdir(dirname($managed), 0777, true);
		file_put_contents($managed, "{}\n");
		symlink($managed, $this->configDir . '/mimetypemapping.json');

		$this->step()->run(new RegisterMimeTypeTestOutput());

		$this->assertTrue(is_link($this->configDir . '/mimetypemapping.json'));
		$decoded = json_decode((string)file_get_contents($managed), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame([PadFileType::MIME], $decoded[PadFileType::EXTENSION]);
	}

	public function testAnInvalidOptionalConfigurationWarnsButDoesNotAbort(): void {
		file_put_contents($this->configDir . '/mimetypealiases.json', '{invalid');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('optional Etherpad file-type icon', $output->warnings[0]);
		$this->assertSame('Etherpad', $this->jsonFile('mimetypenames.json')[PadFileType::MIME]);
		$this->assertContains('Registered .pad files, but some of it was skipped - see the warnings above.', $output->infos);
	}

	/** Earlier versions copied it there; the integrity check reports it and an upgrade deletes it. */
	public function testTakesBackTheIconItUsedToCopyIntoCore(): void {
		$coreIcon = $this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg';
		file_put_contents($coreIcon, '<svg>pad</svg>');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertFileDoesNotExist($coreIcon);
		$this->assertSame([], $output->warnings);
	}

	/** Two failed reads compare equal, which must not read as "ours". */
	public function testLeavesACoreIconItCannotRead(): void {
		$coreIcon = $this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg';
		file_put_contents($coreIcon, '<svg>pad</svg>');
		$appIcon = $this->appDir . '/img/filetypes/etherpad-nextcloud-pad.svg';
		// Both of them: two failed reads are what compares equal.
		chmod($coreIcon, 0000);
		chmod($appIcon, 0000);
		if (is_string(@file_get_contents($coreIcon)) || is_string(@file_get_contents($appIcon))) {
			$this->markTestSkipped('Running as a user that ignores file permissions.');
		}
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertFileExists($coreIcon);
		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('not the icon this app installed', $output->warnings[0]);
	}

	public function testLeavesACoreIconSomebodyElseInstalled(): void {
		$coreIcon = $this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg';
		file_put_contents($coreIcon, '<svg>somebody else</svg>');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertFileExists($coreIcon);
		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('not the icon this app installed', $output->warnings[0]);
	}

	public function testTheRepairStepRunsOnInstallAndAfterMigrations(): void {
		$infoXml = file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertIsString($infoXml);
		$this->assertSame(1, preg_match('/<install>(.*?)<\/install>/s', $infoXml, $install));
		$this->assertSame(1, preg_match('/<post-migration>(.*?)<\/post-migration>/s', $infoXml, $postMigration));

		// The schema fixes the order of these sections, and only the linter
		// sees a violation - the sections themselves read the same either way.
		$this->assertLessThan(
			strpos($infoXml, '<install>'),
			strpos($infoXml, '<post-migration>'),
			'<install> has to follow <post-migration>, as info.xsd sequences them.',
		);

		// Installing runs only the install steps, so everything a fresh
		// instance needs has to be named there as well as after a migration.
		$class = 'OCA\\EtherpadNextcloud\\Migration\\RegisterMimeType';
		$this->assertStringContainsString($class, $install[1]);
		$this->assertStringContainsString($class, $postMigration[1]);
	}

	private function step(
		?IMimeTypeLoader $mimeTypeLoader = null,
		?IAppManager $appManager = null,
	): RegisterMimeType {
		if ($mimeTypeLoader === null) {
			$mimeTypeLoader = $this->createStub(IMimeTypeLoader::class);
			$mimeTypeLoader->method('getId')->willReturnCallback(static fn (string $mime): int => [
				PadFileType::MIME => 42,
				'application' => 7,
				'httpd/unix-directory' => 2,
			][$mime] ?? 99);
		}

		if ($appManager === null) {
			$appManager = $this->createStub(IAppManager::class);
			$appManager->method('getAppPath')->willReturn($this->appDir);
		}

		return new RegisterMimeType(
			$mimeTypeLoader,
			$appManager,
			$this->db,
			$this->appConfig(),
		);
	}

	/** The app config's pad_files_typed, kept in $this->typed. */
	private function appConfig(): AppConfigService {
		$appConfig = $this->createStub(AppConfigService::class);
		$appConfig->method('arePadFilesTyped')->willReturnCallback(fn (): bool => $this->typed);
		$appConfig->method('markPadFilesTyped')->willReturnCallback(function (): void {
			$this->typed = true;
		});
		$appConfig->method('unmarkPadFilesTyped')->willReturnCallback(function (): void {
			$this->typed = false;
		});

		return $appConfig;
	}

	/** @param array<string,mixed> $content */
	private function writeJson(string $name, array $content): void {
		file_put_contents(
			$this->configDir . '/' . $name,
			json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
		);
	}

	/** @return array<string,mixed> */
	private function jsonFile(string $name): array {
		$contents = file_get_contents($this->configDir . '/' . $name);
		$this->assertIsString($contents);
		$decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);

		return $decoded;
	}

	/** @return array<string,string> */
	private function configContents(): array {
		$contents = [];
		foreach (['mimetypemapping.json', 'mimetypealiases.json', 'mimetypenames.json'] as $name) {
			$path = $this->configDir . '/' . $name;
			$content = file_get_contents($path);
			$this->assertIsString($content);
			clearstatcache(true, $path);
			// The inode too: the step writes through a temporary file and
			// renames, so an unnecessary rewrite produces identical bytes in
			// a different file.
			$contents[$name] = [$content, fileinode($path)];
		}

		return $contents;
	}

	private function removeDirectory(string $path): void {
		if (!is_dir($path)) {
			return;
		}

		chmod($path, 0777);
		foreach (new \FilesystemIterator($path) as $item) {
			if ($item->isDir() && !$item->isLink()) {
				$this->removeDirectory($item->getPathname());
				continue;
			}

			if (!$item->isLink()) {
				chmod($item->getPathname(), 0666);
			}
			unlink($item->getPathname());
		}
		rmdir($path);
	}
}

class RegisterMimeTypeTestOutput implements IOutput {
	/** @var list<string> */
	public array $infos = [];
	/** @var list<string> */
	public array $warnings = [];

	public function info(string $message): void {
		$this->infos[] = $message;
	}

	public function warning(string $message): void {
		$this->warnings[] = $message;
	}

	public function startProgress(int $max = 0): void {
	}

	public function advance(int $step = 1, string $description = ''): void {
	}

	public function finishProgress(): void {
	}
}

/** The statements a test runs, written out as SQL fragments to compare. */
class RegisterMimeTypeTestDb implements IDBConnection {
	/** @var list<RegisterMimeTypeTestQuery> */
	public array $statements = [];
	public ?\Exception $failure = null;

	public function getQueryBuilder(): IQueryBuilder {
		return new RegisterMimeTypeTestQuery($this);
	}

	public function escapeLikeParameter(string $param): string {
		return addcslashes($param, '\\_%');
	}
}

class RegisterMimeTypeTestQuery implements IQueryBuilder {
	public ?string $table = null;
	/** @var array<string,mixed> */
	public array $sets = [];
	/** @var list<string> */
	public array $conditions = [];
	/** @var array<string,mixed> */
	private array $params = [];

	public function __construct(private RegisterMimeTypeTestDb $db) {
	}

	public function update(string $table): self {
		$this->table = $table;
		return $this;
	}

	public function set(string $field, string $parameter): self {
		$this->sets[$field] = $this->params[$parameter];
		return $this;
	}

	public function where(string $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function andWhere(string $condition): self {
		$this->conditions[] = $condition;
		return $this;
	}

	public function createNamedParameter(mixed $value, ?int $type = null): string {
		$name = ':p' . count($this->params);
		$this->params[$name] = $value;
		return $name;
	}

	public function value(string $parameter): string {
		$value = $this->params[$parameter];
		return is_string($value) ? "'" . $value . "'" : (string)$value;
	}

	public function expr(): RegisterMimeTypeTestExpr {
		return new RegisterMimeTypeTestExpr($this);
	}

	public function func(): RegisterMimeTypeTestFunc {
		return new RegisterMimeTypeTestFunc();
	}

	public function executeStatement(): int {
		if ($this->db->failure !== null) {
			throw $this->db->failure;
		}
		$this->db->statements[] = $this;
		return 3;
	}
}

class RegisterMimeTypeTestExpr {
	public function __construct(private RegisterMimeTypeTestQuery $query) {
	}

	public function like(string $field, string $parameter): string {
		return $field . ' LIKE ' . $this->query->value($parameter);
	}

	public function neq(string $field, string $parameter): string {
		return $field . ' <> ' . $this->query->value($parameter);
	}

	public function orX(string ...$conditions): string {
		return '(' . implode(' OR ', $conditions) . ')';
	}
}

class RegisterMimeTypeTestFunc {
	public function lower(string $field): string {
		return 'LOWER(' . $field . ')';
	}
}
