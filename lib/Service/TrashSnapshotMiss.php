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
	/**
	 * Waits until its trash lets it go: there is nothing to write a
	 * snapshot into, and an empty file says nothing about the pad, which
	 * may hold the only copy.
	 */
	case FileEmpty = 'file_empty';
	case FileUnreadable = 'file_unreadable';
	case FileUnparsable = 'file_unparsable';
	case SnapshotNotFetched = 'snapshot_not_fetched';
	/** The pad moved on while it was read, or right after it was written into the file. */
	case PadChanged = 'pad_changed';
	/**
	 * Fewer revisions than the file's snapshot: not the pad the file knew.
	 * Its content would take the file's place, so nothing is written. At
	 * trash time the deletion is owed, and the sweep's rule for such a pad
	 * decides: logged, left in place, the row let go.
	 */
	case PadBehind = 'pad_behind';
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
	 * News each time, whatever the row says: Etherpad's silence is not the
	 * file's, and the next run may find it answering.
	 */
	public function reportedEachTime(): bool {
		return $this === self::SnapshotNotFetched;
	}

	/**
	 * The file's own trouble, which does not pass by itself: the row moves
	 * to the back, and counts as reported from then on. What passes by
	 * itself - a lock, a pad that changed while it was read, a file that
	 * moved - is tried again where the row is. So are a pad behind the
	 * file's snapshot and Etherpad's silence: the next run's question to
	 * Etherpad settles those.
	 */
	public function movesTheRowBack(): bool {
		return match ($this) {
			self::FileEmpty, self::FileUnreadable, self::FileUnparsable, self::WriteFailed => true,
			default => false,
		};
	}
}
