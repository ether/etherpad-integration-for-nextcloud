<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/** What ProtectedPadsOfNode::of() found under a node, and whether it stopped before the end. */
final class ProtectedPadsFound {
	/** @param list<string> $pads */
	public function __construct(
		public readonly array $pads,
		public readonly bool $stoppedShort = false,
	) {
	}
}
