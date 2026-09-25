<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * The one line an error a client is answered for gets, for both error
 * mappers; the services under them leave it to this. At the level it
 * deserves:
 *
 * - This instance's Etherpad not reachable: a warning once a minute for
 *   the instance, at debug for the rest of that minute. An outage reaches
 *   every open viewer's sync and every visitor of a share, and each would
 *   otherwise write its own. Refusing: once a minute for each file, since
 *   each is a case of its own. Without a distributed cache to share the
 *   minute with - or one that fails - each is a warning.
 * - A `.pad` and its row that do not match: a warning. That is data an
 *   admin has to mend.
 * - The unforeseen, which the mapper names with the endpoint's line: an
 *   error.
 * - Anything else - what the request itself got wrong, a pad on another
 *   server: debug, with the reason the answer leaves out.
 */
final class ApiErrorLog {
	private const QUIET_SECONDS = 60;
	private const ETHERPAD_UNREACHABLE = 'Etherpad could not be reached while answering a request.';
	private const ETHERPAD_REFUSED = 'Etherpad refused a request.';

	public function __construct(
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $context what the request was about: its file, say
	 * @param ?string $failure the endpoint's line, when the mapper did not foresee $e
	 */
	public function report(\Throwable $e, array $context = [], ?string $failure = null): void {
		$file = self::fileIn($context);
		$context = ['app' => Application::APP_ID, ...$context, ...SafeError::context($e)];
		if (EtherpadClientException::isEtherpadUnreachable($e)) {
			$this->onceAMinute(self::ETHERPAD_UNREACHABLE, 'unreachable', $context);
		} elseif ($e instanceof EtherpadRefusedException) {
			// Each file refused is a case of its own - a pad deleted in
			// Etherpad, a group gone - and its reader was told to ask the
			// admin: once a minute for each file, and each time for a
			// request that names none.
			if ($file !== null) {
				$this->onceAMinute(self::ETHERPAD_REFUSED, 'refused-' . md5($file), $context);
			} else {
				$this->logger->warning(self::ETHERPAD_REFUSED, $context);
			}
		} elseif ($failure !== null) {
			$this->logger->error($failure, $context);
		} elseif ($e instanceof BindingException && !$e instanceof MissingBindingException && !$e instanceof WaitingBindingException) {
			$this->logger->warning('A .pad file and its pad binding could not be matched.', $context);
		} else {
			$this->logger->debug('A request was refused.', $context);
		}
	}

	/**
	 * The file the request names, by id or path, or null.
	 *
	 * @param array<string,mixed> $context
	 */
	private static function fileIn(array $context): ?string {
		foreach (['fileId', 'file'] as $key) {
			if (isset($context[$key]) && is_scalar($context[$key])) {
				return $key . ':' . (string)$context[$key];
			}
		}
		return null;
	}

	/** @param array<string,mixed> $context */
	private function onceAMinute(string $message, string $key, array $context): void {
		if ($this->firstThisMinute($key)) {
			$this->logger->warning($message, $context);
		} else {
			$this->logger->debug($message, $context);
		}
	}

	private function firstThisMinute(string $key): bool {
		if (!$this->cacheFactory->isAvailable()) {
			return true;
		}
		try {
			// add() is IMemcache's; createDistributed() promises only an ICache.
			$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '/api-errors/');
			return !$cache instanceof IMemcache || $cache->add($key, '1', self::QUIET_SECONDS);
		} catch (\Throwable) {
			// The line matters more than the minute: a cache that fails -
			// Redis gone, say - must not fail the answer this reports for.
			return true;
		}
	}
}
