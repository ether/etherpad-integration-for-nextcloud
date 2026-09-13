<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\Lock\LockedException;

/**
 * Gives Files FullTextSearch the stored plain-text pad snapshot.
 *
 * Files FullTextSearch does not treat the app's application/* MIME type as
 * text. Its extension event lets us provide the useful part without sending
 * YAML frontmatter or snapshot HTML through Elasticsearch's attachment
 * processor.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class FullTextSearchIndexingListener implements IEventListener {
	private const INDEXING_EVENT = 'Files_FullTextSearch.onFileIndexing';

	public function __construct(
		private PadFileService $padFileService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GenericEvent || $event->getSubject() !== self::INDEXING_EVENT) {
			return;
		}

		$file = $event->hasArgument('file') ? $event->getArgument('file') : null;
		$document = $event->hasArgument('document') ? $event->getArgument('document') : null;
		if (!$file instanceof File
			|| !$document instanceof IIndexDocument
			|| !PadFileType::isPad($file->getName())) {
			return;
		}

		try {
			$pad = $this->padFileService->readPad((string)$file->getContent());
			$snapshot = $this->padFileService->getSnapshotPartsFromBody($pad->body);
		} catch (PadFileFormatException|GenericFileException|NotPermittedException|LockedException) {
			// Empty, legacy or hand-edited files may be repaired on their next
			// open; temporarily unreadable files can be retried on the next pass.
			$document->setContent('');
			return;
		}

		$document->setContent($snapshot['text']);
	}
}
