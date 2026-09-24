<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * An open that finds its file's row waiting decides the row itself, once,
 * instead of answering waiting_binding until the sweep comes round - after
 * an outage of more than a day that is the daily run, and for a file the
 * sweep cannot read (encrypted with its owner's key, on a storage whose
 * credentials live in the session) it is never. The open runs as someone
 * who can read the file.
 *
 * The decision is the sweep's (RestoreService::settleOpenedFile()), and
 * writes no file: a pad that is there takes the row back, and the file
 * opens; one that is gone or behind lets the row go, and the file offers
 * its own recovery. Under the row's SettleLock, never waited for: a row
 * someone else is deciding is theirs, and the open answers that it waits.
 * Etherpad gets a few seconds; no answer, and the row waits as before.
 *
 * Only a row nobody has touched for a minute: one a trash has just made a
 * deletion owed is its to finish, while the file may still be on its way
 * to the trash, and one tried a moment ago - by the sweep, or by an open
 * whose reader keeps asking - would get the same answer. So an outage
 * costs a call to Etherpad and a log line per row and minute, however
 * often the file is opened, anonymously through a share included.
 *
 * Nothing here fails an open: what goes wrong while deciding is logged,
 * and the row answers as it is.
 */
class SettleOnOpen {
	/** What an open gives Etherpad to decide a row, the clean-up after it included. */
	private const BUDGET_SECONDS = 5.0;
	/** How long a row that was just touched is left to whoever touched it. */
	private const UNTOUCHED_FOR_SECONDS = 60;

	public function __construct(
		private BindingService $bindingService,
		private RestoreService $restoreService,
		private SettleLock $settleLock,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * BindingService::assertConsistentMapping() for the file an open has
	 * just read ($pad), after a row that waits has been decided once: the
	 * row then answers as it is - active, gone, or still waiting. Deciding
	 * costs Etherpad calls and writes to the row, so this is for an open,
	 * never for a path that polls.
	 *
	 * $fileId is $file's, as the caller has it already.
	 *
	 * @throws BindingException
	 */
	public function settleThenAssert(File $file, int $fileId, ParsedPadFile $pad): void {
		try {
			$this->bindingService->assertConsistentMapping($fileId, $pad->padId, $pad->accessMode);
			return;
		} catch (WaitingBindingException) {
			$this->settle($file, $fileId, $pad);
		}
		$this->bindingService->assertConsistentMapping($fileId, $pad->padId, $pad->accessMode);
	}

	private function settle(File $file, int $fileId, ParsedPadFile $pad): void {
		try {
			$binding = $this->bindingService->findByFileId($fileId);
			if ($binding === null || $binding->updatedAt > $this->timeFactory->getTime() - self::UNTOUCHED_FOR_SECONDS) {
				return;
			}
			$this->settleLock->holding(
				$fileId,
				fn (): SettleOutcome => $this->restoreService->settleOpenedFile($file, $binding, $pad, new RunBudget($this->timeFactory, self::BUDGET_SECONDS)),
				static fn (): SettleOutcome => SettleOutcome::Left,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not settle a pad binding that waits while opening its file.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}
	}
}
