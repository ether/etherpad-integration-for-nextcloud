<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Makes the TrashSnapshotWriter for one trashed file and its pad: for the
 * trash, and for the sweep that finishes what the trash could not.
 */
class TrashSnapshotWriters {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private PadFileService $padFileService,
		private LoggerInterface $logger,
		private TestFaults $testFaults,
		private BoundPadResolver $boundPads,
	) {
	}

	/** For $file and the pad its row ($binding) names; $news as TrashSnapshotWriter takes it. */
	public function for(File $file, Binding $binding, bool $news = true): TrashSnapshotWriter {
		return new TrashSnapshotWriter($this->etherpadClient, $this->padFileService, $this->logger, $this->testFaults, $file, $binding, $this->boundPads, $news);
	}
}
