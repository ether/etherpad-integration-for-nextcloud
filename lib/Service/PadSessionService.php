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
use Psr\Log\LoggerInterface;

class PadSessionService {
	private const USER_CONFIG_AUTHOR_ID_KEY = 'etherpad_author_id';
	private const USER_CONFIG_AUTHOR_NAME_KEY = 'etherpad_author_display_name';

	/**
	 * The shape createSession() returns. Anything else in the incoming
	 * cookie is dropped rather than echoed back into a Set-Cookie header.
	 */
	private const SESSION_ID_PATTERN = '/^s\.[A-Za-z0-9]{16,64}$/';

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
	 * How many carried-over ids the degraded path keeps. It cannot tell
	 * groups apart, so it cannot stop one pad — or a cookie written by a
	 * sibling host under the shared parent domain — from filling every
	 * slot. A handful preserves the common two-or-three-tabs case without
	 * offering that much room.
	 */
	private const MAX_UNVERIFIED_SESSION_IDS = 5;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private CookieDomainPolicy $cookieDomainPolicy,
		private IRequest $request,
		private LoggerInterface $logger,
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
	 * A fresh session for the pad being opened, plus the ids the browser
	 * already had that are still worth carrying.
	 *
	 * The cookie is the only place this state lives, and writing just the
	 * new id replaced it — so a second protected pad in a second tab took
	 * the first tab's access away. Etherpad reads the value as a
	 * comma-separated list and picks the entry matching the group, so the
	 * others have to survive the write.
	 *
	 * What may survive is decided by two things together. The browser's
	 * cookie says what this browser already held: nothing is added to it
	 * here that was not already there, so an open never re-issues access to
	 * a pad the user has since lost — it only refrains from taking away what
	 * they were already carrying, which dies at its own validUntil.
	 * Etherpad's session list says which group each of those ids belongs to
	 * and how long it lasts, which is what lets one entry per group survive
	 * rather than one per open: without it, opening the same pad ten times
	 * filled the cookie with ten ids for one group and pushed the other pad
	 * out.
	 *
	 * Ids the list does not know — another author's, which is every public
	 * share, since each share token is its own Etherpad author — are kept
	 * unverified and last, capped tighter, because nothing here can tell
	 * them apart from a value some other host under the shared cookie
	 * domain wrote.
	 *
	 * @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}}
	 */
	private function openContextFor(string $authorId, string $groupId, string $padId, int $validUntil): array {
		$sessions = [];
		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId);
		} catch (EtherpadClientException $e) {
			// Not fatal — the open proceeds on the cookie alone. Logged
			// because that degraded path cannot tell groups apart, so the
			// symptom this method exists to prevent comes back, and nothing
			// else would say why.
			$this->logger->warning('Could not list Etherpad sessions; the session cookie falls back to the browser copy', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
		}

		$chosenSessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);

		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie(
				$this->cookieValueFor($chosenSessionId, $validUntil, $groupId, $sessions),
			),
		];
	}

	/**
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @return array{value:string,expires:int}
	 */
	private function cookieValueFor(string $chosenSessionId, int $validUntil, string $groupId, array $sessions): array {
		$now = time();
		$carried = [];
		$unverified = [];

		foreach ($this->sessionIdsFromCookie() as $candidate) {
			if ($candidate === $chosenSessionId) {
				continue;
			}
			$info = $sessions[$candidate] ?? null;
			if ($info === null) {
				if (count($unverified) < self::MAX_UNVERIFIED_SESSION_IDS) {
					$unverified[] = $candidate;
				}
				continue;
			}
			if ($info['validUntil'] <= $now || $info['groupID'] === $groupId) {
				// Expired, or superseded by the session just created.
				continue;
			}
			$known = $carried[$info['groupID']] ?? null;
			if ($known === null || $info['validUntil'] > $known['validUntil']) {
				$carried[$info['groupID']] = ['sessionId' => $candidate, 'validUntil' => $info['validUntil']];
			}
		}

		// The pad being opened first, then the rest by how long they last,
		// so the cap drops what expires soonest.
		uasort($carried, static fn (array $a, array $b): int => $b['validUntil'] <=> $a['validUntil']);

		$ids = array_merge(
			[$chosenSessionId],
			array_column(array_values($carried), 'sessionId'),
			$unverified,
		);
		$expiries = array_merge(
			[$validUntil],
			array_column(array_values($carried), 'validUntil'),
		);

		return [
			'value' => implode(',', array_slice($ids, 0, self::MAX_SESSION_IDS)),
			// The cookie has to outlive every id it carries, or the browser
			// drops another pad's session that was good for another hour.
			// Unverified ids have no known expiry and cannot extend it.
			'expires' => max($expiries),
		];
	}

	/**
	 * The session ids the browser sent, in the order it sent them.
	 *
	 * Only values shaped like one are read. The cookie is attacker-writable
	 * in principle — any host under the shared parent domain can set it —
	 * and while buildSetCookieHeader percent-encodes the value, so a `;`
	 * cannot smuggle in an attribute, an unbounded length could still be
	 * echoed back as a header no proxy will pass.
	 *
	 * @return list<string>
	 */
	private function sessionIdsFromCookie(): array {
		$existing = (string)($this->request->getCookie('sessionID') ?? '');
		$ids = [];
		foreach (explode(',', trim($existing, '"')) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '' || in_array($candidate, $ids, true)) {
				continue;
			}
			if (preg_match(self::SESSION_ID_PATTERN, $candidate) !== 1) {
				continue;
			}
			$ids[] = $candidate;
		}
		return $ids;
	}

	public function extractGroupId(string $padId): string {
		if (preg_match('/^(g\.[A-Za-z0-9]{16})\$/', $padId, $matches) !== 1) {
			throw new EtherpadClientException('Protected pad ID is invalid (group prefix missing).');
		}
		return $matches[1];
	}

	/** @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string} */
	/** @param array{value:string,expires:int} $cookie */
	private function buildEtherpadSessionCookie(array $cookie): array {
		$cookieDomain = $this->resolveCookieDomain();
		return [
			'name' => 'sessionID',
			'value' => $cookie['value'],
			'expires' => $cookie['expires'],
			'path' => '/',
			'domain' => $cookieDomain,
			'secure' => true,
			// Etherpad reads its session cookie client-side in the pad app, so this
			// must remain script-readable for protected pad opens to work.
			'http_only' => false,
			'same_site' => 'None',
		];
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

	/**
	 * Only reached with a uid whose state is persisted — resolveCachedAuthorId
	 * answers '' for the others and the caller stops there.
	 */
	private function cachedAuthorName(string $uid): string {
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
