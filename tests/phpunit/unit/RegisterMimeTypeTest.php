<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Migration\RegisterMimeType;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RegisterMimeTypeTest extends TestCase {
	private const MIME = 'application/x-etherpad-nextcloud';

	public function testMergeAddsPadMappingWithoutClobberingExistingEntries(): void {
		$current = [
			'txt' => ['text/plain'],
			'md' => ['text/markdown'],
		];
		$new = ['pad' => [self::MIME]];

		$merged = RegisterMimeType::mergeMimeMappings($current, $new);

		// Existing entries are preserved …
		$this->assertSame(['text/plain'], $merged['txt']);
		$this->assertSame(['text/markdown'], $merged['md']);
		// … and the pad mapping is added.
		$this->assertSame([self::MIME], $merged['pad']);
	}

	public function testMergeIsIdempotent(): void {
		$current = ['txt' => ['text/plain']];
		$new = ['pad' => [self::MIME]];

		$once = RegisterMimeType::mergeMimeMappings($current, $new);
		$twice = RegisterMimeType::mergeMimeMappings($once, $new);

		$this->assertSame($once, $twice);
		$this->assertSame([self::MIME], $twice['pad']);
	}

	public function testMergeOverridesAStalePadMapping(): void {
		// A previously-wrong value for our own key is corrected, not duplicated.
		$current = ['pad' => ['application/octet-stream']];
		$new = ['pad' => [self::MIME]];

		$merged = RegisterMimeType::mergeMimeMappings($current, $new);

		$this->assertSame([self::MIME], $merged['pad']);
	}

	public function testFindsTheFiletypeIconInTheConfiguredAppPath(): void {
		$this->withOcDirectories(function (string $root): void {
			$appPath = $root . '/custom_apps/etherpad_nextcloud';
			$coreIconPath = $root . '/core/img/filetypes/etherpad-nextcloud-pad.svg';
			mkdir($appPath . '/img/filetypes', 0777, true);
			mkdir(dirname($coreIconPath), 0777, true);
			file_put_contents($appPath . '/img/filetypes/etherpad-nextcloud-pad.svg', '<svg>pad</svg>');

			$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
			$mimeTypeLoader->method('getId')->with(self::MIME)->willReturn(42);
			$appManager = $this->createMock(IAppManager::class);
			$appManager->expects(self::once())
				->method('getAppPath')
				->with('etherpad_nextcloud')
				->willReturn($appPath);

			$step = new RegisterMimeType(
				$this->createMock(IConfig::class),
				$mimeTypeLoader,
				$appManager,
			);
			$step->run($this->createMock(IOutput::class));

			$this->assertSame('<svg>pad</svg>', file_get_contents($coreIconPath));
		});
	}

	public function testReportsWhenTheConfiguredAppPathCannotBeResolved(): void {
		$this->withOcDirectories(function (): void {
			$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
			$mimeTypeLoader->method('getId')->with(self::MIME)->willReturn(42);
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException());
			$messages = [];
			$output = $this->createMock(IOutput::class);
			$output->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
				$messages[] = $message;
			});

			$step = new RegisterMimeType(
				$this->createMock(IConfig::class),
				$mimeTypeLoader,
				$appManager,
			);
			$step->run($output);

			$this->assertContains('Skipping core filetype icon sync: app path could not be resolved.', $messages);
		});
	}

	public function testDoesNotResolveTheAppPathWithoutAServerRoot(): void {
		$this->withOcDirectories(function (): void {
			$mimeTypeLoader = $this->createMock(IMimeTypeLoader::class);
			$mimeTypeLoader->method('getId')->with(self::MIME)->willReturn(42);
			$appManager = $this->createMock(IAppManager::class);
			$appManager->expects(self::never())->method('getAppPath');
			$messages = [];
			$output = $this->createMock(IOutput::class);
			$output->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
				$messages[] = $message;
			});

			$step = new RegisterMimeType(
				$this->createMock(IConfig::class),
				$mimeTypeLoader,
				$appManager,
			);
			$step->run($output);

			$this->assertContains('Skipping core filetype icon sync: server root is not available.', $messages);
		}, false);
	}

	/** @param callable(string): void $test */
	private function withOcDirectories(callable $test, bool $withServerRoot = true): void {
		$root = sys_get_temp_dir() . '/etherpad-nextcloud-mime-' . bin2hex(random_bytes(8));
		$configPath = $root . '/config';
		mkdir($configPath, 0777, true);

		$previousRoot = \OC::$SERVERROOT;
		$previousConfigDir = \OC::$configDir;
		\OC::$SERVERROOT = $withServerRoot ? $root : null;
		\OC::$configDir = $configPath;

		try {
			$test($root);
		} finally {
			\OC::$SERVERROOT = $previousRoot;
			\OC::$configDir = $previousConfigDir;
			$this->removeDirectory($root);
		}
	}

	private function removeDirectory(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (new \FilesystemIterator($path) as $item) {
			if ($item->isDir() && !$item->isLink()) {
				$this->removeDirectory($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($path);
	}
}
