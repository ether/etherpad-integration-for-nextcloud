<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * What the Etherpad on the other end is old enough to still need.
 *
 * One thing turns on this today: the session cookie. Up to Etherpad 2.7.3
 * the pad app reads `sessionID` itself, in the browser, with
 * `Cookies.get` — so the cookie has to be script-readable and `HttpOnly`
 * would simply lock the user out of every protected pad. From 3.0.0 the
 * server takes it out of the socket.io handshake instead, and the browser
 * never needs to see it.
 *
 * The API version cannot answer this: `/api` says `1.3.1` on both 2.7.3
 * and 3.3.3. `/health` reports a `releaseId`, and that is the only thing
 * that separates them.
 *
 * @psalm-api
 */
class EtherpadReleasePolicy {
	private const APP_ID = 'etherpad_nextcloud';
	private const RELEASE_KEY = 'etherpad_release_version';
	private const CHECKED_AT_KEY = 'etherpad_release_checked_at';

	/** The first release that reads the session cookie server-side. */
	private const HTTP_ONLY_SINCE = '3.0.0';

	/**
	 * How long a detected release is trusted.
	 *
	 * Short, and the reason is the downgrade rather than the upgrade. An
	 * Etherpad that goes 2 → 3 while this is stale only misses a hardening
	 * for an hour. One that goes 3 → 2 with a stale answer would be sent an
	 * `HttpOnly` cookie its pad app cannot read, and every protected pad
	 * would stop opening until the cache turned over.
	 */
	private const TTL_SECONDS = 3600;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the session cookie may be withheld from JavaScript.
	 *
	 * Unknown means no. Getting this wrong in one direction costs a
	 * hardening; in the other it costs every protected pad on the instance,
	 * so the answer without an answer is the one that keeps them working.
	 */
	public function supportsHttpOnlySessionCookie(): bool {
		$release = $this->resolveRelease();
		return $release !== '' && version_compare($release, self::HTTP_ONLY_SINCE, '>=');
	}

	/**
	 * The cached release, refreshed when it has gone stale.
	 *
	 * A detection that fails keeps the last known answer rather than
	 * dropping to unknown: this runs on the open path, and a pad server
	 * that is briefly unreachable should not change how the cookie is
	 * written. It never throws for the same reason — nothing here is worth
	 * failing an open over.
	 */
	private function resolveRelease(): string {
		$cached = trim((string)$this->config->getAppValue(self::APP_ID, self::RELEASE_KEY, ''));
		$checkedAt = (int)$this->config->getAppValue(self::APP_ID, self::CHECKED_AT_KEY, '0');
		$now = $this->timeFactory->getTime();

		if ($cached !== '' && $checkedAt > 0 && ($now - $checkedAt) < self::TTL_SECONDS) {
			return $cached;
		}

		try {
			$release = $this->etherpadClient->detectReleaseVersion();
		} catch (\Throwable $e) {
			$this->logger->debug('Could not read the Etherpad release; keeping the last known one.', [
				'app' => self::APP_ID,
				'cachedRelease' => $cached,
				'exception' => $e,
			]);
			return $cached;
		}

		$this->config->setAppValue(self::APP_ID, self::RELEASE_KEY, $release);
		$this->config->setAppValue(self::APP_ID, self::CHECKED_AT_KEY, (string)$now);
		if ($release !== $cached) {
			$this->logger->info('Detected the Etherpad release.', [
				'app' => self::APP_ID,
				'release' => $release,
				'previousRelease' => $cached,
			]);
		}
		return $release;
	}
}
