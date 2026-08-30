<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class PadSessionService {
	private const USER_CONFIG_AUTHOR_ID_KEY = 'etherpad_author_id';
	private const USER_CONFIG_AUTHOR_NAME_KEY = 'etherpad_author_display_name';

	/**
	 * The shape createSession() returns. Anything else in the incoming
	 * cookie is dropped rather than echoed back into a Set-Cookie header.
	 */
	private const SESSION_ID_PATTERN = '/^s\.[A-Za-z0-9]+$/';

	/**
	 * One entry per group, so this is how many protected pads may be open at
	 * once before the oldest loses access. A session id is ~34 bytes, so 25
	 * of them are under a kilobyte against the 4 KB a cookie may occupy —
	 * and the cookie only ever goes to the pad host. The pad being opened is
	 * always kept; beyond that the ones expiring last win, because the
	 * soonest to expire is the one a user is least likely to still be
	 * looking at.
	 */
	private const MAX_SESSION_IDS = 25;

	/**
	 * A session about to expire is not worth reusing: the pad would lose
	 * access minutes later with no open to renew it.
	 */
	private const REUSE_MIN_REMAINING_SECONDS = 300;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private CookieDomainPolicy $cookieDomainPolicy,
		private IRequest $request,
	) {
	}

	/** @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}} */
	public function createProtectedOpenContext(string $uid, string $displayName, string $padId, int $ttlSeconds = 3600): array {
		$groupId = $this->extractGroupId($padId);
		$effectiveDisplayName = trim($displayName) !== '' ? $displayName : $uid;
		$safeTtlSeconds = max(60, $ttlSeconds);
		$validUntil = time() + $safeTtlSeconds;
		$authorId = $this->resolveCachedAuthorId($uid);
		if ($authorId !== '') {
			$authorId = $this->syncAuthorMapping($uid, $authorId, $effectiveDisplayName);
			try {
				return $this->openContextFor($authorId, $groupId, $padId, $validUntil);
			} catch (EtherpadClientException) {
				$this->clearCachedAuthorState($uid);
			}
		}

		$authorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $effectiveDisplayName);
		$this->rememberAuthorId($uid, $authorId);
		$this->rememberAuthorName($uid, $effectiveDisplayName);
		return $this->openContextFor($authorId, $groupId, $padId, $validUntil);
	}

	/**
	 * The author's sessions, as Etherpad holds them, are the source of truth
	 * for what the cookie should say.
	 *
	 * Building the list from the incoming cookie alone could not get this
	 * right. Every open minted a new session even for a pad already open, so
	 * ten entries meant ten *opens*, not ten pads: opening B ten times
	 * pushed A out and A went back to 403 with two pads on screen. Etherpad
	 * knows which group each session belongs to, so the session for this
	 * group is reused while it lasts and the cookie carries one entry per
	 * group.
	 *
	 * It also stops the sessions piling up. Etherpad deletes none of them —
	 * an author who has opened protected pads for a while accumulates
	 * hundreds, nearly all long expired.
	 *
	 * If Etherpad cannot answer, the open still happens: a fresh session,
	 * and the cookie merged with what the browser sent, which is what this
	 * did before.
	 *
	 * @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}}
	 */
	private function openContextFor(string $authorId, string $groupId, string $padId, int $validUntil): array {
		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId);
		} catch (EtherpadClientException) {
			$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
			return [
				'url' => $this->etherpadClient->buildPadUrl($padId),
				'cookie' => $this->buildEtherpadSessionCookie(
					$this->mergeWithExistingSessionIds($sessionId),
					$validUntil,
				),
			];
		}

		$now = time();
		$reusable = '';
		$otherGroups = [];
		foreach ($sessions as $sessionId => $info) {
			if ($info['validUntil'] <= $now) {
				continue;
			}
			if ($info['groupID'] === $groupId) {
				if ($info['validUntil'] > ($now + self::REUSE_MIN_REMAINING_SECONDS)
					&& ($reusable === '' || $info['validUntil'] > $sessions[$reusable]['validUntil'])) {
					$reusable = $sessionId;
				}
				continue;
			}
			$otherGroups[$sessionId] = $info['validUntil'];
		}

		if ($reusable !== '') {
			$validUntil = $sessions[$reusable]['validUntil'];
			$sessionId = $reusable;
		} else {
			$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
		}

		// The pad being opened first, then the rest by how long they last,
		// so the cap drops what expires soonest.
		arsort($otherGroups);
		$cookieIds = array_merge([$sessionId], array_keys($otherGroups));

		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie(
				implode(',', array_slice($cookieIds, 0, self::MAX_SESSION_IDS)),
				$validUntil,
			),
		];
	}

	public function extractGroupId(string $padId): string {
		if (preg_match('/^(g\.[A-Za-z0-9]{16})\$/', $padId, $matches) !== 1) {
			throw new EtherpadClientException('Protected pad ID is invalid (group prefix missing).');
		}
		return $matches[1];
	}

	/** @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} */
	private function buildEtherpadSessionCookie(string $cookieValue, int $validUntil): array {
		$cookieDomain = $this->resolveCookieDomain();
		return [
			'name' => 'sessionID',
			'value' => $cookieValue,
			'expires' => $validUntil,
			'path' => '/',
			'domain' => $cookieDomain,
			'secure' => true,
			// Etherpad reads its session cookie client-side in the pad app, so this
			// must remain script-readable for protected pad opens to work.
			'http_only' => false,
			'same_site' => 'None',
		];
	}

	/**
	 * Every protected pad lives in its own Etherpad group, and a session is
	 * granted for one group. Writing only the new session id replaced the
	 * one before it, so a second protected pad open in a second tab silently
	 * took the first tab's access away — Etherpad answers 403 for a pad
	 * whose group no longer has a session in the cookie.
	 *
	 * Etherpad reads the cookie as a comma-separated list and picks the
	 * entry matching the group being opened, so the fix is to keep the ones
	 * already there. Newest first: when the cap trims, the oldest goes,
	 * which is the one least likely to still be on screen. Expired entries
	 * need no pruning here — Etherpad checks each against its own validUntil.
	 *
	 * Only values shaped like a session id are carried over. The cookie is
	 * attacker-writable in principle, and while buildSetCookieHeader
	 * percent-encodes the value — so a `;` cannot smuggle in an attribute —
	 * there is no reason to hand anything else back.
	 */
	private function mergeWithExistingSessionIds(string $newSessionId): string {
		$sessionIds = [$newSessionId];
		$existing = (string)($this->request->getCookie('sessionID') ?? '');
		foreach (explode(',', trim($existing, '"')) as $candidate) {
			if (count($sessionIds) >= self::MAX_SESSION_IDS) {
				break;
			}
			$candidate = trim($candidate);
			if ($candidate === '' || in_array($candidate, $sessionIds, true)) {
				continue;
			}
			if (preg_match(self::SESSION_ID_PATTERN, $candidate) !== 1) {
				continue;
			}
			$sessionIds[] = $candidate;
		}
		return implode(',', $sessionIds);
	}

	/** @param array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} $cookie */
	public function buildSetCookieHeader(array $cookie): string {
		$parts = [];
		$parts[] = rawurlencode($cookie['name']) . '=' . rawurlencode($cookie['value']);
		$parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $cookie['expires']);
		$maxAge = max(0, $cookie['expires'] - time());
		$parts[] = 'Max-Age=' . $maxAge;
		$parts[] = 'Path=' . ($cookie['path'] !== '' ? $cookie['path'] : '/');
		if ($cookie['domain'] !== '') {
			$parts[] = 'Domain=' . $cookie['domain'];
		}
		if ($cookie['secure']) {
			$parts[] = 'Secure';
		}
		if ($cookie['http_only']) {
			$parts[] = 'HttpOnly';
		}
		if (($cookie['same_site'] ?? '') !== '') {
			$parts[] = 'SameSite=' . $cookie['same_site'];
		}
		return implode('; ', $parts);
	}

	private function resolveCookieDomain(): string {
		return $this->cookieDomainPolicy->resolve(
			$this->urlGenerator->getBaseUrl(),
			(string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''),
			$this->storedCookieDomain(),
		);
	}

	private function storedCookieDomain(): ?string {
		return $this->cookieDomainPolicy->storedValue(
			(string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_cookie_domain', ''),
			(string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_cookie_domain_configured', 'no') === 'yes',
		);
	}

	private function syncAuthorMapping(string $uid, string $authorId, string $displayName): string {
		$trimmedName = trim($displayName);
		if ($trimmedName === '') {
			return $authorId;
		}

		// Asked on every open, and deliberately not skipped when the stored
		// name still matches. It looks like a round trip the cache should
		// spare, but it is the only thing that keeps Etherpad's idea of the
		// author's name in step with Nextcloud's: the name can drift on the
		// Etherpad side — a user renaming themselves in the pad, another
		// integrator, an API call — and nothing else ever repairs it. The
		// e2e suite catches exactly that, with the pad showing a stale name.
		try {
			$syncedAuthorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $trimmedName);
		} catch (\Throwable) {
			// Do not fail pad open if author name syncing is temporarily unavailable.
			return $authorId;
		}

		if ($syncedAuthorId !== '' && $syncedAuthorId !== $authorId) {
			$this->rememberAuthorId($uid, $syncedAuthorId);
			$authorId = $syncedAuthorId;
		}
		if ($this->cachedAuthorName($uid) !== $trimmedName) {
			$this->rememberAuthorName($uid, $trimmedName);
		}
		return $authorId;
	}

	private function cachedAuthorName(string $uid): string {
		if (!$this->shouldPersistAuthorState($uid)) {
			return '';
		}
		return trim((string)$this->config->getUserValue(
			$uid,
			'etherpad_nextcloud',
			self::USER_CONFIG_AUTHOR_NAME_KEY,
			''
		));
	}

	private function resolveCachedAuthorId(string $uid): string {
		if (!$this->shouldPersistAuthorState($uid)) {
			return '';
		}
		return trim((string)$this->config->getUserValue(
			$uid,
			'etherpad_nextcloud',
			self::USER_CONFIG_AUTHOR_ID_KEY,
			''
		));
	}

	private function rememberAuthorId(string $uid, string $authorId): void {
		if (!$this->shouldPersistAuthorState($uid)) {
			return;
		}
		$this->config->setUserValue($uid, 'etherpad_nextcloud', self::USER_CONFIG_AUTHOR_ID_KEY, trim($authorId));
	}

	private function rememberAuthorName(string $uid, string $displayName): void {
		if (!$this->shouldPersistAuthorState($uid)) {
			return;
		}
		$this->config->setUserValue(
			$uid,
			'etherpad_nextcloud',
			self::USER_CONFIG_AUTHOR_NAME_KEY,
			trim($displayName)
		);
	}

	private function clearCachedAuthorState(string $uid): void {
		if (!$this->shouldPersistAuthorState($uid)) {
			return;
		}
		$this->config->deleteUserValue($uid, 'etherpad_nextcloud', self::USER_CONFIG_AUTHOR_ID_KEY);
		$this->config->deleteUserValue($uid, 'etherpad_nextcloud', self::USER_CONFIG_AUTHOR_NAME_KEY);
	}

	private function shouldPersistAuthorState(string $uid): bool {
		return $uid !== '' && !str_starts_with($uid, 'public-share:');
	}
}
