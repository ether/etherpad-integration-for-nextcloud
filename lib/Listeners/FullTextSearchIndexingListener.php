<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IAppConfig;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

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
	private const FILES_FULLTEXTSEARCH = 'files_fulltextsearch';

	public function __construct(
		private PadFileService $padFileService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
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

		if (!$this->indexesContentOf($document->getSource())) {
			$document->setContent('');
			return;
		}

		try {
			$pad = $this->padFileService->readPad((string)$file->getContent());
			$snapshot = $this->padFileService->getSnapshotPartsFromBody($pad->body);
		} catch (MissingFrontmatterException|PadFileFormatException) {
			// Not a managed pad yet - empty, legacy, or hand-edited. There is
			// no snapshot to index and there will not be until someone opens
			// it, so an empty content field is the honest answer.
			$document->setContent('');
			return;
		} catch (NotFoundException|LockedException|NotPermittedException|GenericFileException $readError) {
			// Deleted mid-run, locked by a sync, permissions in flux. Logged
			// rather than thrown, so one unreadable pad does not cost the run
			// the rest of its files.
			$this->logger->debug('Skipped indexing a .pad that could not be read.', [
				'app' => 'etherpad_nextcloud',
				'file' => $file->getName(),
				'exception' => $readError,
			]);
			$document->setContent('');
			return;
		}

		$document->setContent($snapshot['text']);
	}

	/**
	 * Whether the admin has content indexing on for the storage a file sits on.
	 *
	 * Files FullTextSearch asks this before every extractor it has, but each
	 * of them returns on our MIME type before getting there. Reading its
	 * settings mirrors what its own text extractor would have decided.
	 */
	private function indexesContentOf(string $source): bool {
		return match ($source) {
			'files_local' => $this->appConfig->getValueBool(self::FILES_FULLTEXTSEARCH, 'files_local', true),
			'files_external' => $this->appConfig->getValueInt(self::FILES_FULLTEXTSEARCH, 'files_external') === 1,
			'files_group_folders' => $this->appConfig->getValueBool(self::FILES_FULLTEXTSEARCH, 'files_group_folders'),
			default => false,
		};
	}
}
