<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Hooks;

use OCA\EtherpadNextcloud\Listeners\RestoreFromTrashListener;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\SafeError;
use Psr\Log\LoggerInterface;

/**
 * The restore's legacy hook, which lets nothing out. The file is restored
 * by the time post_restore fires, and whatever this slot threw would only
 * turn that into a failed restore: OC_Hook::emit does not catch an error on
 * Nextcloud 31, 32 before 32.0.14, 33 before 33.0.8 and 34 before 34.0.3,
 * passes HintException and ServerNotAvailableException on everywhere, and
 * writes its entry without the app.
 *
 * @psalm-api
 */
class TrashbinHookHandler {
	/** @param array<string,mixed> $params */
	public static function postRestore(array $params): void {
		// The hook fires for every restored item, folders included. Only a
		// .pad is this app's business, and its name says so before anything
		// is built for it.
		$path = $params['filePath'] ?? null;
		if (!is_string($path) || !PadFileType::isPad($path)) {
			return;
		}

		// Fetched before the one that may fail, so reporting that failure
		// cannot be the second one.
		$logger = self::logger();

		try {
			$listener = \OCP\Server::get(RestoreFromTrashListener::class);
		} catch (\Throwable $e) {
			self::report($logger, 'Legacy trashbin restore hook could not start.', $path, $e);
			return;
		}

		try {
			$listener->handleLegacyHook($params);
		} catch (\Throwable $e) {
			// Unreported so far: the listener keeps its own failures.
			self::report($logger, 'Legacy trashbin restore hook failed.', $path, $e);
		}
	}

	/** For groupfolders, which fires no event after the hook, this line is the only trace, so it names the file. */
	private static function report(?LoggerInterface $logger, string $message, string $path, \Throwable $e): void {
		$logger?->error($message, [
			'app' => 'etherpad_nextcloud',
			'filePath' => $path,
			...SafeError::context($e),
		]);
	}

	/** Null when the container cannot give one. */
	private static function logger(): ?LoggerInterface {
		try {
			return \OCP\Server::get(LoggerInterface::class);
		} catch (\Throwable) {
			return null;
		}
	}
}
