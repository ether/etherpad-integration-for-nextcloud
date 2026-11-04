<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\EtherpadNextcloud\Util\PadViewerAssets;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Loads the pad viewer wherever Nextcloud's Viewer is loaded - Files,
 * Activity, Talk - for a signed-in user the app is enabled for, as
 * LoadFilesScriptsListener does.
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

		PadViewerAssets::load();
	}
}
