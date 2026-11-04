<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the native pad Viewer where Files app scripts are available, for a
 * signed-in user the app is enabled for.
 *
 * @template-implements IEventListener<Event>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private AppAccess $appAccess,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		if (!$this->appAccess->isEnabledForCurrentUser()) {
			return;
		}

		Util::addStyle(Application::APP_ID, 'pad-document');
		Util::addStyle(Application::APP_ID, 'files-main');
		Util::addInitScript(Application::APP_ID, 'etherpad_nextcloud-viewer-init');
	}
}
