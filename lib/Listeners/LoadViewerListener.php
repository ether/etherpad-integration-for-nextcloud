<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Registers the pad Viewer when Nextcloud's Viewer is loaded, for a
 * signed-in user the app is enabled for. A public share page fires
 * LoadViewer too, and LoadPublicShareScriptsListener loads the viewer
 * there, for any visitor.
 *
 * @template-implements IEventListener<Event>
 */
class LoadViewerListener implements IEventListener {
	public function __construct(
		private AppAccess $appAccess,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadViewer) {
			return;
		}
		if (!$this->appAccess->isEnabledForCurrentUser()) {
			return;
		}

		Util::addInitScript(Application::APP_ID, 'etherpad_nextcloud-viewer-init');
	}
}
