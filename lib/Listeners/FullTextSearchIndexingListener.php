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
use OCA\EtherpadNextcloud\Util\SafeError;
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
 * Files FullTextSearch classifies files by MIME type and knows nothing of
 * the app's own. Its extension event is where an app supplies the content of
 * a file it does not recognise, so only the snapshot text is indexed - never
 * the frontmatter or the stored HTML. Which search backend stores the result
 * does not enter into it.
 *
 * @template-implements IEventListener<Event>
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

		$source = $document->getSource();
		$mayIndexContent = $this->indexesContentOf($source);
		$this->recordStorageDecision($document, $source, $mayIndexContent);
		if (!$mayIndexContent) {
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
			// rather than thrown: an exception is recorded on the index, and
			// a document carrying one is skipped by every ordinary run after.
			$this->logger->debug('Skipped indexing a .pad that could not be read.', [
				'app' => 'etherpad_nextcloud',
				'file' => $file->getName(),
				...SafeError::context($readError),
			]);
			$document->setContent('');
			return;
		}

		$document->setContent($snapshot['text']);
	}

	/**
	 * Files FullTextSearch records the same answer on the index and reindexes
	 * a file whose recorded answer no longer matches the setting. A pad
	 * without one would keep whatever it was last indexed with.
	 */
	private function recordStorageDecision(IIndexDocument $document, string $source, bool $mayIndexContent): void {
		if ($source === '' || !$document->hasIndex()) {
			return;
		}

		$document->getIndex()->addOption('_' . $source, $mayIndexContent ? '1' : '0');
	}

	/**
	 * Whether the admin has content indexing on for the storage a file sits on.
	 *
	 * Files FullTextSearch asks this before every extractor it has, but each
	 * of them returns on our MIME type before getting there. Reading its
	 * settings mirrors what its own text extractor would have decided.
	 */
	private function indexesContentOf(string $source): bool {
		try {
			return match ($source) {
				'files_local' => $this->appConfig->getValueBool(self::FILES_FULLTEXTSEARCH, 'files_local', true),
				'files_external' => $this->appConfig->getValueInt(self::FILES_FULLTEXTSEARCH, 'files_external') === 1,
				'files_group_folders' => $this->appConfig->getValueBool(self::FILES_FULLTEXTSEARCH, 'files_group_folders'),
				default => false,
			};
		} catch (\Throwable $typeMismatch) {
			// The value types are theirs, and files_external has already
			// changed from bool to int once. Reading one the other way round
			// throws, and letting it through would have the indexer record an
			// error and then skip every pad on later runs.
			$this->logger->warning('Could not read Files FullTextSearch\'s setting for this storage; indexing no pad content for it.', [
				'app' => 'etherpad_nextcloud',
				'source' => $source,
				...SafeError::context($typeMismatch),
			]);

			return false;
		}
	}
}
