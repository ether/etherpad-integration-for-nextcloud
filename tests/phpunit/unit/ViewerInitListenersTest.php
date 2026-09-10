<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\LoadFilesScriptsListener;
use OCA\EtherpadNextcloud\Listeners\LoadViewerListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ViewerInitListenersTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Util::reset();
	}

	/** @return iterable<string,array{0:IEventListener<Event>,1:Event}> */
	public static function listenerProvider(): iterable {
		yield 'Files app' => [new LoadFilesScriptsListener(), new LoadAdditionalScriptsEvent()];
		yield 'Viewer app' => [new LoadViewerListener(), new LoadViewer()];
	}

	/** @param IEventListener<Event> $listener */
	#[DataProvider('listenerProvider')]
	public function testRegistersViewerInitScript(IEventListener $listener, Event $event): void {
		$listener->handle($event);

		self::assertSame([
			['etherpad_nextcloud', 'etherpad_nextcloud-viewer-init'],
		], Util::$initScripts);
	}
}
