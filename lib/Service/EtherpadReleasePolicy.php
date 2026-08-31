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
 * Which Etherpad releases can be sent a session cookie the browser cannot
 * read.
 *
 * The release, not the API version: `/api` answers `1.3.1` on both 2.7.3
 * and 3.3.3, so only `/health`'s `releaseId` tells them apart.
 *
 * What changes at 3.0, what the cache does and how an admin overrides it
 * are in docs/etherpad-integration.md.
 *
 * @psalm-api
 */
class EtherpadReleasePolicy {
	private const APP_ID = 'etherpad_nextcloud';

	/**
	 * The detected release together with the host it was read from.
	 *
	 * The host belongs inside the record, not in a key beside it. A check
	 * for the previously configured host can finish after the app has been
	 * repointed, and app config is loaded once per request, so that write
	 * can neither be prevented nor noticed — but a record that names its
	 * own host is simply not read as an answer about the new one.
	 */
	private const STATE_KEY = 'etherpad_release_state';

	/**
	 * When a check last failed, and for which host.
	 *
	 * Kept apart from the record above and never written with it: a failing
	 * worker cannot see a success another worker wrote while it waited, so
	 * writing the record back would put a stale release in front of a fresh
	 * one.
	 */
	private const FAILED_KEY = 'etherpad_release_failed';

	/** auto (detect) | yes (force HttpOnly) | no (force a readable cookie). */
	public const OVERRIDE_KEY = 'etherpad_http_only_session_cookie';
	private const OVERRIDE_WARNED_KEY = 'etherpad_http_only_override_warned_at';

	/** How often an ignored override is worth a line. */
	private const OVERRIDE_WARNING_INTERVAL_SECONDS = 3600;

	public const OVERRIDE_AUTO = 'auto';
	public const OVERRIDE_YES = 'yes';
	public const OVERRIDE_NO = 'no';

	/**
	 * The first major that reads the session cookie server-side. Measured
	 * against 2.7.3, 3.0.0 and 3.3.3.
	 *
	 * By major rather than `version_compare`: the e2e spec and the bash
	 * contract restate this rule, and PHP sorts `3.0.0-beta.1` below
	 * `3.0.0` while either of those reads it as a 3.
	 */
	private const HTTP_ONLY_SINCE_MAJOR = 3;

	/**
	 * How long a detected release is trusted. Short because of the
	 * downgrade: a stale 3 means an `HttpOnly` cookie an Etherpad 2 cannot
	 * read, and no protected pad opens.
	 */
	private const TTL_SECONDS = 3600;

	/**
	 * When a release stops being believed at all.
	 *
	 * The TTL above only bounds a downgrade while checks keep succeeding.
	 * This bounds it when they stop: a pad server whose `/health` has been
	 * unreachable for six hours can no longer keep an instance locked out.
	 */
	private const MAX_STALE_SECONDS = 6 * 3600;

	/**
	 * How long a failed check is left alone, so a pad server that accepts
	 * the connection and then says nothing does not cost every open the
	 * health timeout. Also how long the probe claim is held, so a worker
	 * that dies mid-check blocks nothing longer than a retry would.
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

	/** Public and pure so the health check needs no second copy of the rule. */
	public static function allowsHttpOnly(string $release): bool {
		return (int)explode('.', ltrim(trim($release), 'v'))[0] >= self::HTTP_ONLY_SINCE_MAJOR;
	}

	/**
	 * Whether the session cookie may be withheld from JavaScript.
	 *
	 * Unknown means no: being wrong that way costs a hardening, the other
	 * way costs every protected pad on the instance.
	 */
	public function supportsHttpOnlySessionCookie(): bool {
		try {
			$override = $this->overrideMode();
			if ($override !== self::OVERRIDE_AUTO) {
				return $override === self::OVERRIDE_YES;
			}

			return self::allowsHttpOnly($this->resolveReleaseOrThrow());
		} catch (\Throwable $e) {
			// An open never fails because of this. The override read is
			// inside the guard for the same reason: on many requests it is
			// the first app-config read there is.
			$this->logger->warning('Could not work out the Etherpad release; writing a script-readable session cookie.', [
				'app' => self::APP_ID,
				'exception' => $e,
			]);
			return false;
		}
	}

	/** Public so the health check reports on the same reading, not its own. */
	public function overrideMode(): string {
		return $this->readOverride()['mode'];
	}

	/**
	 * The stored override, read once.
	 *
	 * Both public faces come from here rather than each parsing the value:
	 * a fourth accepted spelling added to one and not the other would fail
	 * silently, with the mode resolving correctly while the connection test
	 * calls the same value unrecognised, or the reverse.
	 *
	 * A value that is none of the three is worth saying — `true` or `off`
	 * silently meaning `auto` is the kind of thing that hides an outage —
	 * but this runs on every open, so it is said once an hour.
	 *
	 * @return array{mode:string,unrecognised:string}
	 */
	private function readOverride(): array {
		$override = strtolower($this->read(self::OVERRIDE_KEY));
		if (in_array($override, [self::OVERRIDE_YES, self::OVERRIDE_NO], true)) {
			return ['mode' => $override, 'unrecognised' => ''];
		}
		if (in_array($override, [self::OVERRIDE_AUTO, ''], true)) {
			return ['mode' => self::OVERRIDE_AUTO, 'unrecognised' => ''];
		}

		$unrecognised = substr($override, 0, 32);
		$this->warnAboutOverride($unrecognised);
		return ['mode' => self::OVERRIDE_AUTO, 'unrecognised' => $unrecognised];
	}

	/**
	 * The stamp goes through app config rather than the memory cache so an
	 * instance without one still gets the warning; two workers racing cost
	 * a second line. Best effort: the health check calls this outside the
	 * guard that keeps an open from failing.
	 */
	private function warnAboutOverride(string $value): void {
		try {
			$now = $this->timeFactory->getTime();
			$age = $this->ageOf((int)$this->read(self::OVERRIDE_WARNED_KEY), $now);
			if ($age !== null && $age < self::OVERRIDE_WARNING_INTERVAL_SECONDS) {
				return;
			}

			$this->config->setAppValue(self::APP_ID, self::OVERRIDE_WARNED_KEY, (string)$now);
			$this->logger->warning('Ignoring an unrecognised value for the Etherpad session cookie setting.', [
				'app' => self::APP_ID,
				'setting' => self::OVERRIDE_KEY,
				'value' => $value,
				'expected' => [self::OVERRIDE_AUTO, self::OVERRIDE_YES, self::OVERRIDE_NO],
			]);
		} catch (\Throwable) {
			// Nothing here is worth failing anything over.
		}
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
		return $this->readOverride()['unrecognised'];
	}

	/**
	 * The release the open path is going by, without asking or writing
	 * anything.
	 *
	 * Applies the same staleness rule, or it would not be the same answer —
	 * the admin panel reports this as what pads are being sent.
	 *
	 * @param string|null $host the host to ask about, or null for the
	 *   configured one. A record about a different server is not an answer
	 *   about this one.
	 */
	public function knownRelease(?string $host = null): string {
		try {
			$state = $this->readState($host ?? $this->etherpadClient->getApiHost());
			$age = $this->ageOf($state['checkedAt'], $this->timeFactory->getTime());
			return ($age === null || $age >= self::MAX_STALE_SECONDS) ? '' : $state['release'];
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * The cached release, refreshed when it has gone stale. A failed check
	 * keeps the last known answer rather than dropping to unknown at the
	 * first blip.
	 */
	private function resolveReleaseOrThrow(): string {
		$host = $this->etherpadClient->getApiHost();
		$now = $this->timeFactory->getTime();
		$state = $this->readState($host);

		$cached = $state['release'];
		$age = $this->ageOf($state['checkedAt'], $now);
		if ($cached !== '' && ($age === null || $age >= self::MAX_STALE_SECONDS)) {
			// Debug rather than warning: the probe below already warns about
			// the reason, once per backoff window, while this branch is
			// reached by every open until one succeeds.
			$this->logger->debug('The last known Etherpad release is too old to trust; falling back to a script-readable session cookie.', [
				'app' => self::APP_ID,
				'staleRelease' => $cached,
				'ageSeconds' => $age,
			]);
			$cached = '';
		}

		if ($cached !== '' && $age !== null && $age < self::TTL_SECONDS) {
			return $cached;
		}

		$failedAge = $this->ageOf($this->readFailure($host), $now);
		if ($failedAge !== null && $failedAge < self::FAILURE_BACKOFF_SECONDS) {
			return $cached;
		}

		$cache = $this->probeCache();
		if (!$this->claimTheCheck($cache, $host)) {
			// Another worker is asking right now.
			return $cached;
		}

		try {
			// Passed, not looked up again: the answer is filed under this
			// host, so this host has to be the one asked.
			$release = $this->etherpadClient->detectReleaseVersion($host);
		} catch (\Throwable $e) {
			// Only that it failed, and when — see FAILED_KEY.
			$this->writeFailure($host, $now);
			$this->logger->warning('Could not read the Etherpad release from /health.', [
				'app' => self::APP_ID,
				'host' => $host,
				'cachedRelease' => $cached,
				'exception' => $e,
			]);
			return $cached;
		}

		// The failure stamp is left alone on purpose: clearing it would wipe
		// a backoff another host's check just took. It also needs no
		// clearing — a fresh record returns above, before the stamp is read.
		$this->writeState($host, $release, $now);
		$this->releaseTheClaim($cache, $host);
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
	 * @return array{release:string,checkedAt:int}
	 */
	private function readState(string $host): array {
		$decoded = json_decode($this->read(self::STATE_KEY), true);
		if (!is_array($decoded) || ($decoded['host'] ?? null) !== $host) {
			return ['release' => '', 'checkedAt' => 0];
		}

		$release = $decoded['release'] ?? '';
		return [
			'release' => is_string($release) ? $release : '',
			'checkedAt' => (int)($decoded['checkedAt'] ?? 0),
		];
	}

	private function writeState(string $host, string $release, int $checkedAt): void {
		$this->config->setAppValue(self::APP_ID, self::STATE_KEY, $this->encode([
			'host' => $host,
			'release' => $release,
			'checkedAt' => $checkedAt,
		]));
	}

	/** When a check for this host last failed, or 0. */
	private function readFailure(string $host): int {
		$decoded = json_decode($this->read(self::FAILED_KEY), true);
		return is_array($decoded) && ($decoded['host'] ?? null) === $host
			? (int)($decoded['at'] ?? 0)
			: 0;
	}

	private function writeFailure(string $host, int $now): void {
		$this->config->setAppValue(self::APP_ID, self::FAILED_KEY, $this->encode([
			'host' => $host,
			'at' => $now,
		]));
	}

	/**
	 * Throws rather than storing ''. An empty value reads back as no record
	 * at all, so a silent encoding failure would mean nothing is ever
	 * cached and nothing ever backs off.
	 *
	 * @param array<string,mixed> $value
	 */
	private function encode(array $value): string {
		return json_encode($value, JSON_THROW_ON_ERROR);
	}

	/**
	 * How long ago a stamp was written, or null when it does not say.
	 *
	 * A stamp from the future is not an age: a clock that jumps backwards
	 * would otherwise look younger than every window and freeze the cache.
	 * Null rather than a huge number, because not knowing means "check
	 * again", not "hours stale" — the latter would wipe a fresh cache and
	 * log that it was old.
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
	 * Not through app config: it is loaded once per request, so workers
	 * never see each other's writes and would each spend a round trip on
	 * the same question. A distributed cache is what is actually shared.
	 *
	 * `add()` is declared on `IMemcache`, while `createDistributed()`
	 * promises only an `ICache` — every backend Nextcloud hands back
	 * implements both, the contract does not say so, so it is asked.
	 * Nothing to claim with means one worker per open and nothing to
	 * coordinate, so the check goes ahead.
	 *
	 * Keyed by host, or a claim outlives a repointing. The cache is
	 * resolved once by the caller and handed to both halves, so a claim
	 * cannot be taken from one backend and released against another.
	 */
	private function claimTheCheck(?IMemcache $cache, string $host): bool {
		return $cache === null || $cache->add($this->claimKey($host), '1', self::FAILURE_BACKOFF_SECONDS);
	}

	private function releaseTheClaim(?IMemcache $cache, string $host): void {
		$cache?->remove($this->claimKey($host));
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
