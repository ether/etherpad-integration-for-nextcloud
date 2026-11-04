<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PadViewerAssets;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Util;

/**
 * Loads the pad viewer and compatibility bootstrap on public share pages,
 * for any visitor, a signed-in user outside the app's groups too: a
 * public share decides its own access, and the viewer there calls the
 * public routes. Nextcloud fires LoadViewer on the same page, which
 * LoadViewerListener leaves to this listener for such a user.
 *
 * @template-implements IEventListener<Event>
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

		PadViewerAssets::load();

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
