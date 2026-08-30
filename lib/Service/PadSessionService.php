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
	 * A session id is ~34 bytes, so ten of them stay far inside the 4 KB a
	 * cookie may occupy — and nobody has ten protected pads open whose
	 * sessions all still matter. The newest are kept.
	 */
	private const MAX_SESSION_IDS = 10;

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
				$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
				return [
					'url' => $this->etherpadClient->buildPadUrl($padId),
					'cookie' => $this->buildEtherpadSessionCookie($sessionId, $validUntil),
				];
			} catch (EtherpadClientException) {
				$this->clearCachedAuthorState($uid);
			}
		}

		$authorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $effectiveDisplayName);
		$this->rememberAuthorId($uid, $authorId);
		$this->rememberAuthorName($uid, $effectiveDisplayName);
		$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie($sessionId, $validUntil),
		];
	}

	public function extractGroupId(string $padId): string {
		if (preg_match('/^(g\.[A-Za-z0-9]{16})\$/', $padId, $matches) !== 1) {
			throw new EtherpadClientException('Protected pad ID is invalid (group prefix missing).');
		}
		return $matches[1];
	}

	/** @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} */
	private function buildEtherpadSessionCookie(string $sessionId, int $validUntil): array {
		$cookieDomain = $this->resolveCookieDomain();
		return [
			'name' => 'sessionID',
			'value' => $this->mergeWithExistingSessionIds($sessionId),
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

		// The cached id only saves a round trip if it is allowed to. This
		// asked Etherpad for the author on every open and compared the name
		// afterwards, so the cache spared nothing and a repeat open of the
		// same pad cost two API calls where one would do. The stored name is
		// the question being asked, so ask it first.
		//
		// Skipping the call also skips the incidental proof that the author
		// still exists — createSession answers that a moment later, and its
		// failure already clears the cache and retries from scratch.
		if ($this->cachedAuthorName($uid) === $trimmedName) {
			return $authorId;
		}

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
