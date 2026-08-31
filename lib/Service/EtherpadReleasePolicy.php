<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
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
 * Every answer here is reversible by an admin: `etherpad_http_only_session_cookie`
 * is `auto` by default and takes `yes` or `no`. The failure mode of getting
 * this wrong is total — not one protected pad opens — so it does not rest
 * on detection alone.
 *
 * @psalm-api
 */
class EtherpadReleasePolicy {
	private const APP_ID = 'etherpad_nextcloud';
	private const RELEASE_KEY = 'etherpad_release_version';
	private const CHECKED_AT_KEY = 'etherpad_release_checked_at';
	private const HOST_KEY = 'etherpad_release_host';
	private const FAILED_AT_KEY = 'etherpad_release_failed_at';

	/** auto (detect) | yes (force HttpOnly) | no (force a readable cookie). */
	public const OVERRIDE_KEY = 'etherpad_http_only_session_cookie';
	public const OVERRIDE_AUTO = 'auto';
	public const OVERRIDE_YES = 'yes';
	public const OVERRIDE_NO = 'no';

	/**
	 * The first major that reads the session cookie server-side.
	 *
	 * Measured, not read off a changelog, and measured at the boundary
	 * rather than around it: with `HttpOnly` forced on, a protected pad on
	 * **2.7.3** loads and never becomes usable, while **3.0.0** and
	 * **3.3.3** work. The e2e stack takes a full tag, so `EP_VERSION=3.0.0`
	 * reproduces the middle one.
	 *
	 * Compared by major on purpose. A patch-level comparison would have to
	 * answer what `3.0.0-beta.1` is, and PHP sorts that *below* `3.0.0`
	 * while the e2e spec — which has to make the same call in TypeScript —
	 * would read the major and disagree. One rule, expressible in both
	 * languages, and the thing that actually changed was the major.
	 */
	private const HTTP_ONLY_SINCE_MAJOR = 3;

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
	 * When a release stops being believed at all.
	 *
	 * The sentence above only holds if the cache does turn over, and on the
	 * failure path it did not: the last known release was served again on
	 * every open and nothing ever dropped it. An admin who moves back to an
	 * Etherpad 2 whose `/health` is unreachable — behind proxy auth, or
	 * simply not routed — would have had a locked-out instance forever,
	 * asked once a minute.
	 *
	 * So a release that has not been confirmed for six hours stops counting,
	 * and the class falls back to the answer it defines for not knowing.
	 * Long enough to ride out an outage, and an outage of the pad server is
	 * not a time when pads open anyway; short enough that a misconfiguration
	 * heals inside a working day, with the override for anyone who cannot
	 * wait.
	 */
	private const MAX_STALE_SECONDS = 6 * 3600;

	/**
	 * How long a failed check is left alone.
	 *
	 * Without it, a pad server that accepts the connection and then says
	 * nothing costs every single open the health timeout, for as long as it
	 * stays that way. This bounds that to one attempt a minute. It is also
	 * how long the claim below is held, so a worker that dies mid-check
	 * blocks nothing for longer than a retry would have waited anyway.
	 */
	private const FAILURE_BACKOFF_SECONDS = 60;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether a release reads its session cookie server-side.
	 *
	 * Public and pure so the admin health check can say what the cookie will
	 * be without a second copy of the rule.
	 */
	public static function allowsHttpOnly(string $release): bool {
		return (int)explode('.', ltrim(trim($release), 'v'))[0] >= self::HTTP_ONLY_SINCE_MAJOR;
	}

	/**
	 * Whether the session cookie may be withheld from JavaScript.
	 *
	 * Unknown means no. Getting this wrong in one direction costs a
	 * hardening; in the other it costs every protected pad on the instance,
	 * so the answer without an answer is the one that keeps them working.
	 */
	public function supportsHttpOnlySessionCookie(): bool {
		try {
			$override = $this->overrideMode();
			if ($override !== self::OVERRIDE_AUTO) {
				return $override === self::OVERRIDE_YES;
			}

			return self::allowsHttpOnly($this->resolveReleaseOrThrow());
		} catch (\Throwable $e) {
			// The promise this class makes: an open never fails because of
			// it. Reading the override is inside the guard too — it is the
			// first app-config read of some requests, and a database blip
			// there would otherwise escape a predicate that says it cannot.
			$this->logger->warning('Could not work out the Etherpad release; writing a script-readable session cookie.', [
				'app' => self::APP_ID,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * What the admin has decided, if anything.
	 *
	 * Public so the admin health check reports on the same reading rather
	 * than parsing the value a second time with its own default — the same
	 * reason `allowsHttpOnly()` is public: one rule, one place.
	 */
	public function overrideMode(): string {
		$override = strtolower($this->read(self::OVERRIDE_KEY));
		if (in_array($override, [self::OVERRIDE_YES, self::OVERRIDE_NO, self::OVERRIDE_AUTO, ''], true)) {
			return $override === '' ? self::OVERRIDE_AUTO : $override;
		}

		$this->logger->warning('Ignoring an unrecognised value for the Etherpad session cookie setting.', [
			'app' => self::APP_ID,
			'setting' => self::OVERRIDE_KEY,
			'value' => substr($override, 0, 32),
			'expected' => [self::OVERRIDE_AUTO, self::OVERRIDE_YES, self::OVERRIDE_NO],
		]);
		return self::OVERRIDE_AUTO;
	}

	/**
	 * A stored override that is none of the three words, or '' when there
	 * is no such problem.
	 *
	 * This is the setting an admin reaches for while protected pads are
	 * down, typed into `occ` from memory: `true`, `1`, `off`, `disabled`.
	 * Every one of those silently means `auto`, and the connection test
	 * would then confirm the automatic behaviour as fine — actively telling
	 * them nothing is overridden. So it is worth being able to say.
	 */
	public function unrecognisedOverride(): string {
		$override = strtolower($this->read(self::OVERRIDE_KEY));
		if (in_array($override, [self::OVERRIDE_YES, self::OVERRIDE_NO, self::OVERRIDE_AUTO, ''], true)) {
			return '';
		}

		return substr($override, 0, 32);
	}

	/** The release currently believed, without asking anything. For the admin view. */
	public function knownRelease(): string {
		return $this->read(self::RELEASE_KEY);
	}

	/**
	 * The cached release, refreshed when it has gone stale.
	 *
	 * A detection that fails keeps the last known answer for a while rather
	 * than dropping to unknown at the first blip: this runs on the open
	 * path, and a pad server that is briefly unreachable should not change
	 * how the cookie is written.
	 */
	private function resolveReleaseOrThrow(): string {
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
		$age = $this->ageOf(self::CHECKED_AT_KEY, $now);
		if ($cached !== '' && $age !== null && $age >= self::MAX_STALE_SECONDS) {
			// Nothing has confirmed this for hours. Whatever it says, it is
			// no longer evidence about the server answering today.
			$this->logger->warning('The last known Etherpad release is too old to trust; falling back to a script-readable session cookie.', [
				'app' => self::APP_ID,
				'staleRelease' => $cached,
				'ageSeconds' => $age,
			]);
			$this->forgetFor($host);
			$cached = '';
		}

		if ($cached !== '' && $age !== null && $age < self::TTL_SECONDS) {
			return $cached;
		}

		$failedAge = $this->ageOf(self::FAILED_AT_KEY, $now);
		if ($failedAge !== null && $failedAge < self::FAILURE_BACKOFF_SECONDS) {
			return $cached;
		}
		if (!$this->claimTheCheck($host)) {
			// Another worker is asking right now.
			return $cached;
		}

		try {
			$release = $this->etherpadClient->detectReleaseVersion();
		} catch (\Throwable $e) {
			$this->config->setAppValue(self::APP_ID, self::FAILED_AT_KEY, (string)$now);
			$this->logger->warning('Could not read the Etherpad release from /health.', [
				'app' => self::APP_ID,
				'cachedRelease' => $cached,
				'exception' => $e,
			]);
			return $cached;
		}

		$this->config->setAppValue(self::APP_ID, self::RELEASE_KEY, $release);
		$this->config->setAppValue(self::APP_ID, self::CHECKED_AT_KEY, (string)$now);
		$this->config->setAppValue(self::APP_ID, self::FAILED_AT_KEY, '0');
		$this->releaseTheClaim($host);
		if ($release !== $cached) {
			$this->logger->info('Detected the Etherpad release.', [
				'app' => self::APP_ID,
				'release' => $release,
				'previousRelease' => $cached,
			]);
		}
		return $release;
	}

	/**
	 * How long ago a stamp was written, or null when it does not say.
	 *
	 * A clock that jumps backwards — an NTP correction, a restored snapshot,
	 * a cluster node that drifts — leaves a stamp ahead of `now`, and a
	 * negative age is younger than every window there is: the cache would
	 * freeze until real time caught up, hours in which a downgrade goes
	 * unnoticed. So a stamp from the future is not an age at all.
	 *
	 * Null rather than a very large number, because the two readings of
	 * "no usable age" differ. Not knowing means check again now. It does
	 * not mean the release is hours stale — answering that would wipe a
	 * cache seconds old and log that it was hours old, over a clock that
	 * moved by a second.
	 */
	private function ageOf(string $key, int $now): ?int {
		$stamp = (int)$this->read($key);
		if ($stamp <= 0 || $stamp > $now) {
			return null;
		}
		return $now - $stamp;
	}

	/**
	 * Take the right to make the check, if nobody else holds it.
	 *
	 * The obvious way to do this — write the timestamp before asking — does
	 * not work across workers. Nextcloud loads an app's config once per
	 * request and serves later reads from that copy, so a hundred opens
	 * arriving together have all already read the old value and none of
	 * them sees anybody else's write. They would each spend a round trip
	 * on the same question.
	 *
	 * A distributed cache is the thing that is actually shared. `add()`
	 * succeeds for exactly one caller, which is the whole requirement — but
	 * it is declared on `IMemcache`, not on the `ICache` that
	 * `createDistributed()` promises. Every backend Nextcloud actually
	 * hands back implements it; the contract does not say so, so it is
	 * asked rather than assumed.
	 *
	 * Not every deployment has a memory cache at all. One without has a
	 * worker per open and nothing to coordinate with, so going ahead is the
	 * right answer there — as it is for a backend that cannot claim.
	 *
	 * Keyed by host, because a claim outlives a repointing otherwise: the
	 * new server's first check would be turned away by a claim taken for
	 * the old one. Released as soon as the answer is written, so the next
	 * refresh is not waiting out a minute that has already done its job.
	 */
	private function claimTheCheck(string $host): bool {
		$cache = $this->probeCache();
		return $cache === null || $cache->add($this->claimKey($host), '1', self::FAILURE_BACKOFF_SECONDS);
	}

	private function releaseTheClaim(string $host): void {
		$this->probeCache()?->remove($this->claimKey($host));
	}

	private function claimKey(string $host): string {
		return 'probe-' . md5($host);
	}

	private function probeCache(): ?IMemcache {
		if (!$this->cacheFactory->isAvailable()) {
			return null;
		}

		$cache = $this->cacheFactory->createDistributed(self::APP_ID . '/release/');
		return $cache instanceof IMemcache ? $cache : null;
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
