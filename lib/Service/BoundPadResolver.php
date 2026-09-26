<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\BindingMismatchException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use Psr\Log\LoggerInterface;

/**
 * Which pad a `.pad` file of this app's opens, reads and syncs: the one its
 * row names, and only while the file names it too - or names the pad the
 * row's replaced (Binding::$replacedPadId). Anything else is refused. The
 * file follows the row that far and no further, and the row never follows
 * the file (docs/architecture.md, "Which pad a file reaches").
 *
 * Pads on another server have no row: this is for the app's own.
 */
class BoundPadResolver {
	public function __construct(
		private BindingService $bindingService,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * $pad as its active row has it: $pad itself when it names the row's
	 * pad, the file as namingPad() makes it when it names the pad the row's
	 * replaced.
	 *
	 * @throws MissingBindingException the file has no row
	 * @throws BindingMismatchException the file names neither pad
	 * @throws WaitingBindingException the row waits for the sweep
	 * @throws BindingException any other state
	 */
	public function resolve(int $fileId, ParsedPadFile $pad): ParsedPadFile {
		$binding = $this->bindingService->findByFileId($fileId);
		if ($binding === null) {
			throw new MissingBindingException('No binding exists for this file.');
		}
		// Before the state: a file that names neither pad is not this row's
		// to settle or wait for.
		$bound = $this->followingRow($pad, $binding);
		if ($bound === $pad) {
			if ($binding->padId !== $pad->padId) {
				throw new BindingMismatchException('Binding pad ID mismatch.');
			}
			if ($binding->accessMode !== $pad->accessMode) {
				throw new BindingMismatchException('Binding access mode mismatch.');
			}
		}
		if ($binding->isWaiting()) {
			throw new WaitingBindingException('Pad binding is not active.');
		}
		if ($binding->state !== BindingService::STATE_ACTIVE) {
			// A state the sweep never takes up, so nothing to wait for either.
			throw new BindingException('Pad binding is not active.');
		}
		if ($bound !== $pad) {
			$this->logger->debug('A .pad file names the pad its row replaced; the row\'s pad is used.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
			]);
		}
		return $bound;
	}

	/**
	 * For a caller that holds the row already, a trash, a sweep or a
	 * restore: $pad as namingPad() makes it when it names the pad $binding's
	 * replaced, otherwise $pad as it is. Such a file holds a revision of the
	 * pad before, not of the row's: held against the row's pad, a higher
	 * one would make it look behind (docs/architecture.md, "Which pad a
	 * file reaches").
	 */
	public function followingRow(ParsedPadFile $pad, Binding $binding): ParsedPadFile {
		if ($pad->padId !== $binding->replacedPadId || $pad->padId === $binding->padId) {
			return $pad;
		}
		return $this->padFileService->namingPad($pad, $binding->padId, $binding->accessMode, $this->etherpadClient->buildPadUrl($binding->padId));
	}

	/** A file that named the replaced pad has been written to name the row's. */
	public function repaired(int $fileId): void {
		$this->logger->warning('A .pad file named the pad its row replaced; it now names the row\'s.', [
			'app' => 'etherpad_nextcloud',
			'fileId' => $fileId,
		]);
	}
}
