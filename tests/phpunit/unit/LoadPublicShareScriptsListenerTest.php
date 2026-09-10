<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\LoadPublicShareScriptsListener;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Share\IShare;
use OCP\Util;
use PHPUnit\Framework\TestCase;

class LoadPublicShareScriptsListenerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Util::reset();
	}

	public function testLoadsBootstrapForSinglePadShare(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Shared.pad');

		$this->handle($file);

		$this->assertViewerInitLoaded();
		$this->assertPublicShareBootstrapLoaded();
	}

	public function testLoadsBootstrapForLegacyFolderSelection(): void {
		$this->handle($this->createMock(Folder::class), 'Shared.PAD');

		$this->assertViewerInitLoaded();
		$this->assertPublicShareBootstrapLoaded();
	}

	public function testLeavesOrdinaryFolderShareNavigationToNextcloud(): void {
		$this->handle($this->createMock(Folder::class));

		$this->assertViewerInitLoaded();
		self::assertSame([], Util::$scripts);
	}

	public function testLoadsNothingForThePasswordPrompt(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getParam');
		$share = $this->createMock(IShare::class);

		$listener = new LoadPublicShareScriptsListener($request);
		$listener->handle(new BeforeTemplateRenderedEvent(
			BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH,
			$share,
		));

		self::assertSame([], Util::$initScripts);
		self::assertSame([], Util::$scripts);
	}

	private function handle(File|Folder $node, mixed $selectedFile = ''): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('files', '')->willReturn($selectedFile);

		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node);

		$listener = new LoadPublicShareScriptsListener($request);
		$listener->handle(new BeforeTemplateRenderedEvent('publicShare', $share));
	}

	private function assertPublicShareBootstrapLoaded(): void {
		self::assertSame([
			['etherpad_nextcloud', 'etherpad_nextcloud-public-share-main', 'viewer'],
		], Util::$scripts);
	}

	private function assertViewerInitLoaded(): void {
		self::assertSame([
			['etherpad_nextcloud', 'etherpad_nextcloud-viewer-init'],
		], Util::$initScripts);
	}
}
