<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

use OCP\Files\Folder;

/** The user may not create a file in the folder a `.pad` is to be made in. */
class PadParentFolderNotWritableException extends \RuntimeException {
	/** The one check for a folder to create a `.pad` in. */
	public static function unlessCreatable(Folder $folder): void {
		if (!$folder->isCreatable()) {
			throw new self('The folder takes no new file.');
		}
	}
}
