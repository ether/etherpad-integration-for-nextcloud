<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Util;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoadPublicShareScriptsListener implements IEventListener {
	public function __construct(
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent) {
			return;
		}
		if ($event->getScope() === BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH) {
			return;
		}

		Util::addStyle(Application::APP_ID, 'pad-document');
		Util::addStyle(Application::APP_ID, 'files-main');
		Util::addInitScript(Application::APP_ID, 'etherpad_nextcloud-viewer-init');

		$node = $event->getShare()->getNode();
		$selectedFile = $this->request->getParam('files', '');
		$isSinglePadShare = $node instanceof File && PadFileType::isPad($node->getName());
		$isLegacyFolderSelection = $node instanceof Folder
			&& is_string($selectedFile)
			&& PadFileType::isPad($selectedFile);

		if ($isSinglePadShare || $isLegacyFolderSelection) {
			Util::addScript(Application::APP_ID, 'etherpad_nextcloud-public-share-main', 'viewer');
		}
	}
}
