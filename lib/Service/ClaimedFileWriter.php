<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCP\Files\File;

/**
 * Writes into the `.pad` file a claim names, and tells whether that file
 * is still as the claim left it. The file is found again by its id and
 * written only if it holds what the claim expects when read, so content
 * written before that read is not written over.
 */
class ClaimedFileWriter {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
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
	 * Re-resolve by id and require the claimed content before writing.
	 *
	 * File::putContent() uses its remembered path, which may have been reused
	 * meanwhile. The content check and write are not atomic.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/Node/File.php
	 * @throws \OCP\Files\NotFoundException
	 * @throws PadFileChangedException
	 */
	public function write(CreatedFileClaim $claim, string $content): void {
		$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		if ($node->getContent() !== $claim->expectedBefore()) {
			throw new PadFileChangedException('The .pad file changed before its document was written.');
		}
		$node->putContent($content);
		$claim->writtenHash = hash('sha256', $content);
	}

	/**
	 * Resolve for recovery only if the original content or recorded write still matches.
	 * Return null if the file changed or cannot be resolved or read. This does not lock it.
	 */
	public function resolveUnchanged(CreatedFileClaim $claim): ?File {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
			$current = $node->getContent();
			$isOurs = $current === $claim->expectedBefore()
				|| ($claim->writtenHash !== null && hash_equals($claim->writtenHash, hash('sha256', $current)));
			if (!$isOurs) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}

		return $node;
	}
}
