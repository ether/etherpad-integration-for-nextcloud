<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * This instance's Etherpad failing while a request is answered, for both
 * error mappers: a warning once a minute for the instance, at debug for
 * the rest of that minute. An outage reaches every open viewer's sync and
 * every visitor of a share; each would otherwise write its own warning.
 * Without a distributed cache there is nothing to share the minute with,
 * and each failure is a warning.
 */
final class EtherpadFailureLog {
	private const QUIET_SECONDS = 60;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Etherpad failing, and ours: not a pad too large to show, which is no
	 * failure, and not a pad on another server, which no admin here can
	 * mend.
	 */
	public static function isOwnEtherpadFailing(\Throwable $e): bool {
		return $e instanceof EtherpadClientException
			&& !$e instanceof EtherpadTooLargeException
			&& !$e instanceof ExternalPadException;
	}

	/** @param array<string,mixed> $context what the request was about: its file, say */
	public function report(string $message, \Throwable $e, array $context = []): void {
		$context = ['app' => Application::APP_ID, ...$context, ...SafeError::context($e)];
		if ($this->firstThisMinute()) {
			$this->logger->warning($message, $context);
		} else {
			$this->logger->debug($message, $context);
		}
	}

	private function firstThisMinute(): bool {
		if (!$this->cacheFactory->isAvailable()) {
			return true;
		}
		// add() is IMemcache's; createDistributed() promises only an ICache.
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '/etherpad-failed/');
		return !$cache instanceof IMemcache || $cache->add('warned', '1', self::QUIET_SECONDS);
	}
}
