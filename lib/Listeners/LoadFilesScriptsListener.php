<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		Util::addStyle(Application::APP_ID, 'pad-document');
		Util::addStyle(Application::APP_ID, 'files-main');
		// viewer-main registers the MIME handler Nextcloud's own viewer action
		// opens a pad with, and is what carries this page. files-main only has
		// work here when the Viewer app is absent: its route watchers redirect
		// to this app's own viewer instead. With Viewer present every one of
		// its entry points returns immediately.
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-files-main', 'files');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-viewer-main', 'files');
	}
}
