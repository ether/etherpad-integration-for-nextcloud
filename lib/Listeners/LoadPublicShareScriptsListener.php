<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoadPublicShareScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent) {
			return;
		}
		if ($event->getScope() === BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH) {
			return;
		}

		Util::addStyle(Application::APP_ID, 'pad-document');
		Util::addStyle(Application::APP_ID, 'files-main');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-files-main', 'files_sharing');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-viewer-main', 'files_sharing');
	}
}
