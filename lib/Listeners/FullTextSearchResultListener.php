<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IURLGenerator;

/**
 * Gives a pad in a search result the app's own icon.
 *
 * Search results carry no preview, so Files FullTextSearch picks their icon
 * from the MIME type and lands on whatever Nextcloud ships for it. This
 * extension event is dispatched after that choice, and the pad icon lives in
 * the app rather than in Nextcloud's signed core directory.
 *
 * @template-implements IEventListener<Event>
 */
class FullTextSearchResultListener implements IEventListener {
	private const SEARCH_RESULT_EVENT = 'Files_FullTextSearch.onSearchResult';
	private const ICON = 'filetypes/etherpad-nextcloud-pad.svg';

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GenericEvent || $event->getSubject() !== self::SEARCH_RESULT_EVENT) {
			return;
		}

		$result = $event->hasArgument('result') ? $event->getArgument('result') : null;
		if (!$result instanceof ISearchResult) {
			return;
		}

		$icon = null;
		foreach ($result->getDocuments() as $document) {
			if (!PadFileType::isPad($document->getTitle())) {
				continue;
			}

			$icon ??= $this->urlGenerator->imagePath(Application::APP_ID, self::ICON);
			$unified = $document->getInfoArray('unified');
			$unified['icon'] = $icon;
			$document->setInfoArray('unified', $unified);
		}
	}
}
