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
	private const HOST_KEY = 'etherpad_release_host';
	private const FAILED_AT_KEY = 'etherpad_release_failed_at';

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

	/**
	 * How long a failed check is left alone.
	 *
	 * Without it, a pad server that accepts the connection and then says
	 * nothing costs every single open the health timeout, for as long as it
	 * stays that way – the failure path returns the last known release but
	 * records nothing, so the next open starts over. This bounds that to one
	 * attempt a minute. Much shorter than the TTL: a check that failed has
	 * told us nothing, so there is nothing to hold on to for an hour.
	 */
	private const FAILURE_BACKOFF_SECONDS = 60;

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
		$host = $this->etherpadClient->getApiHost();
		$now = $this->timeFactory->getTime();

		if ($this->read(self::HOST_KEY) !== $host) {
			// A different pad server answers now. What the last one said
			// about itself says nothing about this one, and an admin who
			// repoints the app at an Etherpad 2 must not have it sent a
			// cookie its pad app cannot read for the rest of the hour.
			$this->forgetFor($host);
		}

		$cached = $this->read(self::RELEASE_KEY);
		if ($cached !== '' && $now - (int)$this->read(self::CHECKED_AT_KEY) < self::TTL_SECONDS) {
			return $cached;
		}
		if ($now - (int)$this->read(self::FAILED_AT_KEY) < self::FAILURE_BACKOFF_SECONDS) {
			return $cached;
		}

		try {
			$release = $this->etherpadClient->detectReleaseVersion();
		} catch (\Throwable $e) {
			$this->config->setAppValue(self::APP_ID, self::FAILED_AT_KEY, (string)$now);
			$this->logger->debug('Could not read the Etherpad release; keeping the last known one.', [
				'app' => self::APP_ID,
				'cachedRelease' => $cached,
				'exception' => $e,
			]);
			return $cached;
		}

		$this->config->setAppValue(self::APP_ID, self::RELEASE_KEY, $release);
		$this->config->setAppValue(self::APP_ID, self::CHECKED_AT_KEY, (string)$now);
		$this->config->setAppValue(self::APP_ID, self::FAILED_AT_KEY, '0');
		if ($release !== $cached) {
			$this->logger->info('Detected the Etherpad release.', [
				'app' => self::APP_ID,
				'release' => $release,
				'previousRelease' => $cached,
			]);
		}
		return $release;
	}

	private function read(string $key): string {
		return trim((string)$this->config->getAppValue(self::APP_ID, $key, ''));
	}

	/** Drop everything known about a release, and note whose it would be now. */
	private function forgetFor(string $host): void {
		$this->config->setAppValue(self::APP_ID, self::HOST_KEY, $host);
		$this->config->setAppValue(self::APP_ID, self::RELEASE_KEY, '');
		$this->config->setAppValue(self::APP_ID, self::CHECKED_AT_KEY, '0');
		$this->config->setAppValue(self::APP_ID, self::FAILED_AT_KEY, '0');
	}
}
