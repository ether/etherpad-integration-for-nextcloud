<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Registers the pad Viewer when Nextcloud's Viewer is loaded.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoadViewerListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadViewer) {
			return;
		}

		Util::addInitScript(Application::APP_ID, 'etherpad_nextcloud-viewer-init');
	}
}
