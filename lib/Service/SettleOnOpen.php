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
 * rather than wait for the sweep (docs/architecture.md says what that
 * reaches). The decision is the sweep's, on the row as it was checked here
 * and from the file as the open read it (RestoreService::settleOpenedFile()).
 *
 * - Under the row's SettleLock, only ever tried: a row someone else is
 *   deciding is theirs.
 * - Within BUDGET_SECONDS, the clean-up after a pad that is gone included.
 * - Only a row nobody has touched for UNTOUCHED_FOR_SECONDS: one a trash
 *   has just made a deletion owed is the trash's to finish, and one tried
 *   a moment ago would get the same answer - which also keeps an outage
 *   to one Etherpad call per row and minute, however often it is opened.
 * - Nothing here fails an open: what goes wrong while deciding is logged,
 *   and the row answers as it is.
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
