<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\LifecycleResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LifecycleResultTest extends TestCase {
	/**
	 * What a trash and a restore answer, key for key and in order: the API
	 * hands it out as it is, after the file.
	 */
	public function testATrashAndARestoreAnswerInTheShapeTheApiShows(): void {
		$this->assertSame(['status' => 'trashed', 'deleted_at' => 100, 'snapshot_persisted' => true, 'delete_pending' => false], LifecycleResult::trashed(100, true, false));
		$this->assertSame(['status' => 'restored', 'old_pad_id' => 'pad-a', 'new_pad_id' => 'pad-b'], LifecycleResult::restored('pad-a', 'pad-b'));
	}

	/**
	 * A skipped step answers with its reason alone, and leaves one line at
	 * debug level naming the file: for a row the sweep cannot settle yet,
	 * the only trace of why.
	 */
	public function testASkippedStepIsLoggedWithItsReason(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Lifecycle step skipped.', ['app' => 'etherpad_nextcloud', 'reason' => 'not_pad_file', 'fileId' => 7]);

		$this->assertSame(['status' => LifecycleResult::SKIPPED, 'reason' => 'not_pad_file'], LifecycleResult::skipped('not_pad_file', 7, $logger));
	}
}
