<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Writes into the `.pad` file a claim names, tells whether that file is
 * still as the claim left it, and removes it for a create that failed.
 * Each step finds the file again by the claim's id: a retained File acts
 * on its remembered path, which may name another file by then. The file
 * is written or removed only if it is as the claim left it when read, so
 * content written before that read is neither written over nor removed.
 */
class ClaimedFileWriter {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The document naming the pad, written into the claimed file.
	 *
	 * @return string the pad's URL
	 * @throws \OCP\Files\NotFoundException
	 * @throws PadFileChangedException
	 */
	public function writePadDocument(CreatedFileClaim $claim, string $padId, string $accessMode, ?PadSnapshot $snapshot = null): string {
		$padUrl = $this->etherpadClient->buildPadUrl($padId);
		$this->write($claim, $this->padFileService->buildInitialDocument($claim->fileId, $padId, $accessMode, $snapshot, $padUrl));

		return $padUrl;
	}

	/**
	 * Write $content while the file is as the claim left it
	 * (CreatedFileClaim::holds()). The content check and write are not
	 * atomic. A read that gives no string fails the write; cast to a
	 * string, it would pass for an empty file.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/Node/File.php
	 * @throws \OCP\Files\NotFoundException
	 * @throws PadFileChangedException
	 */
	public function write(CreatedFileClaim $claim, string $content): void {
		$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		if (!$claim->holds($node->getContent())) {
			throw new PadFileChangedException('The .pad file changed before its document was written.');
		}
		$node->putContent($content);
		$claim->writtenHash = hash('sha256', $content);
	}

	/**
	 * The claimed file while it is as the claim left it
	 * (CreatedFileClaim::holds()); null, with a line saying why, when it is
	 * not, or cannot be resolved or read. This does not lock it. The read
	 * goes to holds() uncast, so one that gives no string is left as a read
	 * that failed rather than passing for an empty file.
	 *
	 * @param array<string,mixed> $context the caller's keys for that line
	 */
	public function resolveUnchanged(CreatedFileClaim $claim, array $context = []): ?File {
		$context = ['app' => 'etherpad_nextcloud', 'uid' => $claim->uid, 'fileId' => $claim->fileId] + $context;
		try {
			try {
				$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
			} catch (NotFoundException) {
				// The id may still name a file outside the user's files, so the
				// skip is logged.
				$this->logger->info('Left a claimed .pad file alone: its id did not resolve.', $context);
				return null;
			}
			if ($claim->holds($node->getContent())) {
				return $node;
			}
			$this->logger->warning('Left a .pad file in place: it is not as this create left it.', $context + ['written' => $claim->writtenHash !== null]);
		} catch (\Throwable $e) {
			$this->logger->warning('Left a .pad file in place: it could not be looked up or read.', $context + SafeError::context($e));
		}

		return null;
	}

	/**
	 * Remove the claimed file of a create that failed, while it is as the
	 * claim left it. Best effort: a removal that fails is logged, not
	 * thrown, so the create's own failure goes on. The check and the delete
	 * are not atomic.
	 *
	 * @param array<string,mixed> $context as for resolveUnchanged()
	 */
	public function deleteIfUnchanged(CreatedFileClaim $claim, array $context = []): void {
		$node = $this->resolveUnchanged($claim, $context);
		try {
			$node?->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove the .pad file of a failed create.', ['app' => 'etherpad_nextcloud', 'uid' => $claim->uid, 'fileId' => $claim->fileId] + $context + SafeError::context($e));
		}
	}
}
