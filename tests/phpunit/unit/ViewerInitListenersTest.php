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
use OCA\EtherpadNextcloud\Tests\Support\BuildsAppAccess;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Covers Viewer bootstrap registration from both Files and Viewer events. */
class ViewerInitListenersTest extends TestCase {
	use BuildsAppAccess;

	protected function setUp(): void {
		parent::setUp();
		Util::reset();
	}

	/** @return iterable<string,array{0:class-string,1:Event,2:?bool,3:bool}> */
	public static function listenerProvider(): iterable {
		foreach ([
			'Files app' => [LoadFilesScriptsListener::class, new LoadAdditionalScriptsEvent()],
			'Viewer app' => [LoadViewerListener::class, new LoadViewer()],
		] as $label => [$listener, $event]) {
			yield "$label, a user of the app" => [$listener, $event, true, true];
			// Its viewer would call routes Nextcloud refuses that user.
			yield "$label, a user outside the app's groups" => [$listener, $event, false, false];
			// A public share page loads it through its own listener.
			yield "$label, no user" => [$listener, $event, null, false];
		}
	}

	/** @param class-string<IEventListener<Event>> $listener */
	#[DataProvider('listenerProvider')]
	public function testRegistersViewerInitScriptOnlyForAUserOfTheApp(string $listener, Event $event, ?bool $enabledForUser, bool $loads): void {
		(new $listener($this->appAccess($enabledForUser)))->handle($event);

		self::assertSame($loads ? [
			['etherpad_nextcloud', 'etherpad_nextcloud-viewer-init'],
		] : [], Util::$initScripts);
	}
}
