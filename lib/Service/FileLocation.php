<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Where a deletion owed's file is, as the file cache tells it: the kinds a
 * sweep asks for in turn, so rows it cannot settle yet do not crowd out
 * the ones it can. A file in a team folder's trash on the root storage is
 * in none of them (BindingService::findPendingDeleteByAge()).
 */
enum FileLocation {
	/** No file cache row left at all. */
	case Gone;
	/** In its owner's trash. */
	case InUserTrash;
	/** Anywhere else - in Files, or a team folder's trash with a storage of its own. */
	case Elsewhere;
}
