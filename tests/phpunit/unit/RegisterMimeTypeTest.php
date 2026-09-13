<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\RegisterMimeType;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RegisterMimeTypeTest extends TestCase {
	private string $root;
	private string $configDir;
	private string $appDir;
	private mixed $previousServerRoot;
	private mixed $previousConfigDir;

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
	}

	protected function tearDown(): void {
		\OC::$SERVERROOT = $this->previousServerRoot;
		\OC::$configDir = $this->previousConfigDir;
		$this->removeDirectory($this->root);

		parent::tearDown();
	}

	public function testRegistersTheCompleteMimeConfigurationOnAFirstRun(): void {
		$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
		$mimeTypeLoader->expects(self::once())
			->method('getId')
			->with(PadFileType::MIME)
			->willReturn(42);
		$mimeTypeLoader->expects(self::once())
			->method('updateFilecache')
			->with(PadFileType::EXTENSION, 42);

		$output = new RegisterMimeTypeTestOutput();
		$this->step($mimeTypeLoader)->run($output);

		$this->assertSame(
			[PadFileType::MIME],
			$this->jsonFile('mimetypemapping.json')[PadFileType::EXTENSION],
		);
		$this->assertSame(
			'etherpad-nextcloud-pad',
			$this->jsonFile('mimetypealiases.json')[PadFileType::MIME],
		);
		$this->assertSame(
			'Etherpad',
			$this->jsonFile('mimetypenames.json')[PadFileType::MIME],
		);
		$this->assertSame(
			'<svg>pad</svg>',
			file_get_contents($this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg'),
		);
		$this->assertSame([], $output->warnings);
		$this->assertContains('Registered .pad files and backfilled their MIME type.', $output->infos);
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
		$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
		$mimeTypeLoader->method('getId')->willReturn(42);
		$mimeTypeLoader->expects(self::once())->method('updateFilecache');
		$output = new RegisterMimeTypeTestOutput();

		try {
			$this->step($mimeTypeLoader)->run($output);
			self::fail('Invalid required MIME configuration must abort registration.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('contains invalid JSON', $e->getMessage());
		}

		$this->assertSame([], $output->infos);
	}

	public function testAlreadyCorrectReadOnlyConfigurationSucceeds(): void {
		$this->writeJson('mimetypemapping.json', [
			PadFileType::EXTENSION => [PadFileType::MIME],
		]);
		$this->writeJson('mimetypealiases.json', [
			PadFileType::MIME => 'etherpad-nextcloud-pad',
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
		$this->assertContains('Registered .pad files and backfilled their MIME type.', $output->infos);
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
		$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
		$mimeTypeLoader->method('getId')->willReturn(42);
		$mimeTypeLoader->expects(self::once())->method('updateFilecache');
		$output = new RegisterMimeTypeTestOutput();

		try {
			$this->step($mimeTypeLoader)->run($output);
			self::fail('A failed required write must abort registration.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Could not write MIME configuration file', $e->getMessage());
		}

		$this->assertSame([], $output->infos);
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

	public function testAnInvalidOptionalConfigurationWarnsButDoesNotAbort(): void {
		file_put_contents($this->configDir . '/mimetypealiases.json', '{invalid');
		$output = new RegisterMimeTypeTestOutput();

		$this->step()->run($output);

		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('optional Etherpad file-type icon', $output->warnings[0]);
		$this->assertSame('Etherpad', $this->jsonFile('mimetypenames.json')[PadFileType::MIME]);
		$this->assertContains('Registered .pad files, but some of it was skipped - see the warnings above.', $output->infos);
	}

	public function testWarnsWhenTheAppPathCannotBeResolved(): void {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException());
		$output = new RegisterMimeTypeTestOutput();

		$this->step(appManager: $appManager)->run($output);

		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('the app path is unavailable', $output->warnings[0]);
		$this->assertFileDoesNotExist($this->root . '/core/img/filetypes/etherpad-nextcloud-pad.svg');
		$this->assertContains('Registered .pad files, but some of it was skipped - see the warnings above.', $output->infos);
	}

	/** Without one there is no core icon directory to copy into, so nothing is looked up. */
	public function testDoesNotResolveTheAppPathWithoutAServerRoot(): void {
		\OC::$SERVERROOT = '';
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects(self::never())->method('getAppPath');
		$output = new RegisterMimeTypeTestOutput();

		$this->step(appManager: $appManager)->run($output);

		$this->assertCount(1, $output->warnings);
		$this->assertStringContainsString('the server root is unavailable', $output->warnings[0]);
	}

	public function testTheRepairStepRunsOnInstallAndAfterMigrations(): void {
		$infoXml = file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertIsString($infoXml);
		$this->assertSame(1, preg_match('/<install>(.*?)<\/install>/s', $infoXml, $install));
		$this->assertSame(1, preg_match('/<post-migration>(.*?)<\/post-migration>/s', $infoXml, $postMigration));

		// Installing runs only the install steps, so everything a fresh
		// instance needs has to be named there as well as after a migration.
		foreach (['RegisterMimeType', 'BackfillPadMimeType'] as $step) {
			$class = 'OCA\\EtherpadNextcloud\\Migration\\' . $step;
			$this->assertStringContainsString($class, $install[1]);
			$this->assertStringContainsString($class, $postMigration[1]);
		}
	}

	private function step(
		?IMimeTypeLoader $mimeTypeLoader = null,
		?IAppManager $appManager = null,
	): RegisterMimeType {
		if ($mimeTypeLoader === null) {
			$mimeTypeLoader = $this->createStub(IMimeTypeLoader::class);
			$mimeTypeLoader->method('getId')->willReturn(42);
		}

		if ($appManager === null) {
			$appManager = $this->createStub(IAppManager::class);
			$appManager->method('getAppPath')->willReturn($this->appDir);
		}

		return new RegisterMimeType(
			$mimeTypeLoader,
			$appManager,
		);
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
			$content = file_get_contents($this->configDir . '/' . $name);
			$this->assertIsString($content);
			$contents[$name] = $content;
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

			chmod($item->getPathname(), 0666);
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
