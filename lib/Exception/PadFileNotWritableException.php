<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

use OCP\Files\File;

/** The user may read the .pad file but not change it, and what was asked would change it. */
class PadFileNotWritableException extends \RuntimeException {
	/** The one check for an action that writes the file, asked before anything is made. */
	public static function unlessUpdateable(File $file): void {
		if (!$file->isUpdateable()) {
			throw new self('The user may not change this .pad file.');
		}
	}
}
