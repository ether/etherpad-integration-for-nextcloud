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

	/**
	 * Everything known about the pad server's release, in one value.
	 *
	 * One value rather than four keys, and the host lives inside it. A
	 * check for the host configured a moment ago can finish after the app
	 * has been repointed, and with a separate host marker its write would
	 * file the old server's release under the new server's name — for an
	 * hour, and for an Etherpad 2 that means no protected pad opens at all.
	 * Neither ordering nor a re-read before writing can prevent that:
	 * Nextcloud loads an app's config once per request, so the late writer
	 * cannot see the repointing at all.
	 *
	 * Keeping the host in the record does prevent it. A stale write can
	 * only produce a record that says which server it is about, and the
	 * next reader compares that against the host configured now and throws
	 * it away. The cost of losing the race is one extra check.
	 */
	private const STATE_KEY = 'etherpad_release_state';

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
		try {
			return $this->readState($this->etherpadClient->getApiHost())['release'];
		} catch (\Throwable) {
			return '';
		}
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
		$state = $this->readState($host);

		$cached = $state['release'];
		$age = $this->ageOf($state['checkedAt'], $now);
		if ($cached !== '' && $age !== null && $age >= self::MAX_STALE_SECONDS) {
			// Nothing has confirmed this for hours. Whatever it says, it is
			// no longer evidence about the server answering today.
			$this->logger->warning('The last known Etherpad release is too old to trust; falling back to a script-readable session cookie.', [
				'app' => self::APP_ID,
				'staleRelease' => $cached,
				'ageSeconds' => $age,
			]);
			$cached = '';
		}

		if ($cached !== '' && $age !== null && $age < self::TTL_SECONDS) {
			return $cached;
		}

		$failedAge = $this->ageOf($state['failedAt'], $now);
		if ($failedAge !== null && $failedAge < self::FAILURE_BACKOFF_SECONDS) {
			return $cached;
		}
		if (!$this->claimTheCheck($host)) {
			// Another worker is asking right now.
			return $cached;
		}

		try {
			// The host is passed rather than looked up again inside the
			// client: the answer is filed under this one, so this one has to
			// be the one that was asked.
			$release = $this->etherpadClient->detectReleaseVersion($host);
		} catch (\Throwable $e) {
			$this->writeState($host, $cached, $state['checkedAt'], $now);
			$this->logger->warning('Could not read the Etherpad release from /health.', [
				'app' => self::APP_ID,
				'cachedRelease' => $cached,
				'exception' => $e,
			]);
			return $cached;
		}

		$this->writeState($host, $release, $now, 0);
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
	 * What is on record about this host, or nothing when the record is
	 * about a different one.
	 *
	 * @return array{release:string,checkedAt:int,failedAt:int}
	 */
	private function readState(string $host): array {
		$empty = ['release' => '', 'checkedAt' => 0, 'failedAt' => 0];
		$decoded = json_decode($this->read(self::STATE_KEY), true);
		if (!is_array($decoded) || ($decoded['host'] ?? null) !== $host) {
			return $empty;
		}

		$release = $decoded['release'] ?? '';
		return [
			'release' => is_string($release) ? $release : '',
			'checkedAt' => (int)($decoded['checkedAt'] ?? 0),
			'failedAt' => (int)($decoded['failedAt'] ?? 0),
		];
	}

	private function writeState(string $host, string $release, int $checkedAt, int $failedAt): void {
		$this->config->setAppValue(self::APP_ID, self::STATE_KEY, (string)json_encode([
			'host' => $host,
			'release' => $release,
			'checkedAt' => $checkedAt,
			'failedAt' => $failedAt,
		]));
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
	private function ageOf(int $stamp, int $now): ?int {
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

}
