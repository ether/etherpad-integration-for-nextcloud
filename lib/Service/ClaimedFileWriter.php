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
 * Writes into a `.pad` file a create or a first open holds a claim on, and
 * tells whether that file is still as the claim left it. One guard for
 * all of them: a write that took the file as it was handed over would
 * destroy whatever was written into it meanwhile.
 */
class ClaimedFileWriter {
	public function __construct(
		private UserNodeResolver $userNodeResolver,
		private PadFileService $padFileService,
		private EtherpadClient $etherpadClient,
	) {
	}

	/**
	 * The document naming a new pad, written into the claimed file.
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
	 * during provisioning. The content check and write are not atomic.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/Node/File.php
	 * @throws \OCP\Files\NotFoundException
	 * @throws PadFileChangedException
	 */
	public function write(CreatedFileClaim $claim, string $content): void {
		$node = $this->userNodeResolver->resolveUserFileNodeById($claim->uid, $claim->fileId);
		if ((string)$node->getContent() !== $claim->expectedBefore()) {
			throw new PadFileChangedException('The .pad file changed while its pad was being provisioned.');
		}
		$node->putContent($content);
		// Record on the shared claim so recovery retains proof if binding fails next.
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
