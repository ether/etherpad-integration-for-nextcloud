<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Why a trashed file did not get its snapshot this time, as the `reason`
 * its log line carries. What each one means lives here: whether it is
 * worth a warning, and whether it moves the row to the back.
 */
enum TrashSnapshotMiss: string {
	case FileLocked = 'file_locked';
	case FileEmpty = 'file_empty';
	case FileUnreadable = 'file_unreadable';
	case FileUnparsable = 'file_unparsable';
	case SnapshotNotFetched = 'snapshot_not_fetched';
	case PadChanged = 'pad_changed';
	case FileMoved = 'file_moved';
	case WriteFailed = 'write_failed';

	/** Someone has to look, at the file or at Etherpad: a warning the first time. */
	public function needsALook(): bool {
		return match ($this) {
			self::FileUnreadable, self::FileUnparsable, self::WriteFailed, self::SnapshotNotFetched => true,
			default => false,
		};
	}

	/**
	 * The file's own trouble, which does not pass by itself: the row moves
	 * to the back, and counts as reported from then on. What passes by
	 * itself - a lock, a pad that changed while it was read, a file that
	 * moved - is tried again where the row is.
	 */
	public function movesTheRowBack(): bool {
		return match ($this) {
			self::FileEmpty, self::FileUnreadable, self::FileUnparsable, self::WriteFailed => true,
			default => false,
		};
	}
}
