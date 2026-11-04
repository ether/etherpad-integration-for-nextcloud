<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\EtherpadNextcloud\Util\PadViewerAssets;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Loads the pad viewer where Files app scripts are available, for a
 * signed-in user the app is enabled for: the viewer calls the app's own
 * routes, which Nextcloud refuses everyone else.
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

		PadViewerAssets::load();
	}
}
