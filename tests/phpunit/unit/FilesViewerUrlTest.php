<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Util\FilesViewerUrl;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class FilesViewerUrlTest extends TestCase {
	public function testBuildsTheFilesAppAddressForAFile(): void {
		$this->assertSame(
			'/apps/files/files/42?dir=%2FFolder&editing=false&openfile=true',
			FilesViewerUrl::forFile($this->urlGenerator('/apps/files'), 42, '/Folder/Notes.pad'),
		);
	}

	public function testUsesTheRootDirectoryForAFileAtTheTop(): void {
		$this->assertSame(
			'/apps/files/files/7?dir=%2F&editing=false&openfile=true',
			FilesViewerUrl::forFile($this->urlGenerator('/apps/files'), 7, '/Notes.pad'),
		);
	}

	public function testDropsATrailingSlashFromTheRoute(): void {
		$this->assertSame(
			'/apps/files/files/7?dir=%2F&editing=false&openfile=true',
			FilesViewerUrl::forFile($this->urlGenerator('/apps/files/'), 7, '/Notes.pad'),
		);
	}

	public function testEscapesADirectoryThatWouldOtherwiseEndTheQuery(): void {
		$this->assertSame(
			'/apps/files/files/9?dir=%2FA%26B%20%3Fx&editing=false&openfile=true',
			FilesViewerUrl::forFile($this->urlGenerator('/apps/files'), 9, '/A&B ?x/Notes.pad'),
		);
	}

	private function urlGenerator(string $filesRoute): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('files.view.index')->willReturn($filesRoute);
		return $urlGenerator;
	}
}
