<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCP\IConfig;
use OCA\EtherpadNextcloud\Util\PadId;
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
	 * once before the oldest loses access. An Etherpad session id is `s.`
	 * plus 16 characters, and buildSetCookieHeader percent-encodes the comma
	 * between them, so 25 of them cost 25×18 + 24×3 = 522 bytes against the
	 * 4 KB a cookie may occupy. It
	 * is not a pad-host-only cookie — it is scoped to the domain both hosts
	 * share, so Nextcloud and every sibling under that parent see it too,
	 * which is how this request can read it at all. The pad being opened is
	 * always kept; beyond that the ones expiring last win, because the
	 * soonest to expire is the one a user is least likely to still be
	 * looking at.
	 */
	private const MAX_SESSION_IDS = 25;

	/** `lax` (default) or `none`; see sameSiteMode(). */
	public const SAME_SITE_KEY = 'etherpad_session_cookie_samesite';
	public const SAME_SITE_LAX = 'Lax';
	public const SAME_SITE_NONE = 'None';

	/**
	 * How many ids are read out of the cookie at all. Only a bound on work:
	 * the cap above decides what survives. Any host under the shared parent
	 * domain can write this cookie, and without a limit a forged value would
	 * decide how much parsing and comparing each open does. Twice the emit
	 * cap, so a legitimate cookie is never truncated by it.
	 */
	private const MAX_PARSED_SESSION_IDS = 50;

	public function __construct(
		private EtherpadClient $etherpadClient,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private CookieDomainPolicy $cookieDomainPolicy,
		private EtherpadReleasePolicy $releasePolicy,
		private IRequest $request,
		private ExpiredSessionCollector $collector,
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
				return $this->openContextFor($uid, $authorId, $groupId, $padId, $validUntil);
			} catch (EtherpadClientException) {
				$this->clearCachedAuthorState($uid);
			}
		}

		$authorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $effectiveDisplayName);
		$this->rememberAuthorId($uid, $authorId);
		$this->rememberAuthorName($uid, $effectiveDisplayName);
		return $this->openContextFor($uid, $authorId, $groupId, $padId, $validUntil);
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
	 * cookie says what this browser already held, so an open adds nothing
	 * that was not already there — it does not re-issue access to a pad the
	 * user has since lost, it only refrains from taking away what they were
	 * carrying, which dies at its own validUntil. Etherpad's session list
	 * then says which of those ids are this author's, which group each is
	 * for, and how long it lasts — that is what lets one entry per group
	 * survive rather than one per open: without it, opening the same pad ten
	 * times filled the cookie with ten ids for one group and pushed the
	 * other pad out.
	 *
	 * Ids the list does not know are dropped. That covers a public share's
	 * session — each share token is its own Etherpad author — so a share and
	 * an authenticated pad cannot be open at once, as was already the case
	 * before any of this. It also covers the session of whoever used this
	 * browser before, which is why the rule is worth the loss: nothing here
	 * can tell those two apart, and carrying them would hand a pad to the
	 * next person to log in.
	 *
	 * @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}}
	 */
	private function openContextFor(string $uid, string $authorId, string $groupId, string $padId, int $validUntil): array {
		// Before the listing below, which only happens when the browser
		// carries ids — a first open makes none, and a public link never
		// does.
		$this->collector->noteAuthor($authorId);

		$carriedIds = $this->sessionIdsFromCookie();
		$sessions = $this->sessionsToAttributeWith($uid, $authorId, $carriedIds);

		// Deliberately a fresh session, not the one the browser is carrying.
		// Etherpad re-checks validUntil on every socket message and holds the
		// session id it was given at CLIENT_READY — read in 2.7.3, 3.0.0 and
		// 3.3.3, so both majors and the boundary between them. A session that
		// expires mid-edit therefore rejects the next keystroke, and no later
		// cookie can reach that socket. Reusing a shorter one traded editing
		// time for a renewal property that a client arriving without a cookie
		// does not have anyway. What bounds the window is revocation.
		$chosenSessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
		if (preg_match(self::SESSION_ID_PATTERN, $chosenSessionId) !== 1) {
			// The id is about to be written into a cookie that the next open
			// has to be able to read back. If Etherpad's shape ever moves
			// outside what sessionIdsFromCookie accepts, every later open
			// would silently find an empty cookie and write a single id
			// again — this branch's bug, restored, with nothing to say so.
			$this->logger->warning('Etherpad returned a session id in an unexpected shape; carrying sessions between pads will not work', [
				'app' => 'etherpad_nextcloud',
			]);
		}

		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie(
				$this->cookieValueFor($chosenSessionId, $validUntil, $groupId, $carriedIds, $sessions),
			),
		];
	}

	/**
	 * What the carried ids can be checked against, or an empty list when
	 * they cannot be checked at all.
	 *
	 * Not asked for when there is nothing to check — the first protected
	 * open of a browsing session, and every open for anyone who only ever
	 * has one pad open, costs no extra round trip.
	 *
	 * Not asked for on a public share either. There the author is derived
	 * from the share token alone, so every anonymous visitor of one link
	 * shares it, and Etherpad deletes no sessions: a link opened by five
	 * hundred people carries five hundred sessions under one author, and
	 * every open would download the lot. The cost is that two protected
	 * pads inside one shared folder cannot be open at once, which is what
	 * happened before this branch anyway.
	 *
	 * @param list<string> $carriedIds
	 * @return array<string,array{groupID:string,validUntil:int}>
	 */
	private function sessionsToAttributeWith(string $uid, string $authorId, array $carriedIds): array {
		if ($carriedIds === [] || !$this->shouldPersistAuthorState($uid)) {
			return [];
		}

		try {
			$sessions = $this->etherpadClient->listSessionsOfAuthor($authorId);
		} catch (EtherpadClientException $e) {
			// Not fatal: the open goes ahead. But nothing can be attributed
			// without the listing, so nothing is carried and a second pad
			// loses access exactly as it did before this existed — which is
			// a symptom nothing else would explain.
			$this->logger->warning('Could not list Etherpad sessions; this open drops the other pads\' sessions from the cookie', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
			return [];
		}

		return $sessions;
	}

	/**
	 * @param list<string> $carriedIds
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @return array{value:string,expires:int}
	 */
	private function cookieValueFor(
		string $chosenSessionId,
		int $validUntil,
		string $groupId,
		array $carriedIds,
		array $sessions,
	): array {
		$now = time();
		$carried = [];

		foreach ($carriedIds as $candidate) {
			$info = $sessions[$candidate] ?? null;
			if ($info === null) {
				// Not this author's, so not this user's: dropped. It used to
				// be carried, on the grounds that a public share is its own
				// Etherpad author and its session would look exactly like
				// this — but so does the session of whoever was logged into
				// this browser before. Keeping it let the next user inherit
				// their pad until it expired, where overwriting the cookie
				// had cut that off. Nothing here can tell the two apart, and
				// only one of them is safe to guess at.
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

		if ($carried === [] && $carriedIds !== [] && $sessions !== []) {
			// The browser brought ids and this author owns none of them.
			// Expected after a user switch — that is the case the rule exists
			// for — but it also happens when the author id itself was
			// re-issued, and then the user loses their other open pads for a
			// reason that looks exactly like the bug this prevents.
			$this->logger->debug('None of the session ids the browser sent belong to this Etherpad author; the other pads drop out of the cookie', [
				'app' => 'etherpad_nextcloud',
			]);
		}

		// The pad being opened first, then the rest by how long they last,
		// so the cap drops what expires soonest.
		uasort($carried, static fn (array $a, array $b): int => $b['validUntil'] <=> $a['validUntil']);

		$ids = array_merge(
			[$chosenSessionId],
			array_column(array_values($carried), 'sessionId'),
		);
		$expiries = array_merge(
			[$validUntil],
			array_column(array_values($carried), 'validUntil'),
		);

		return [
			'value' => implode(',', array_slice($ids, 0, self::MAX_SESSION_IDS)),
			// The cookie has to outlive every id it carries, or the browser
			// drops another pad's session that was good for another hour.
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
			if (count($ids) >= self::MAX_PARSED_SESSION_IDS) {
				break;
			}
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
		$groupId = PadId::groupIdOf($padId);
		if ($groupId === null) {
			throw new EtherpadClientException('Protected pad ID is invalid (group prefix missing).');
		}
		return $groupId;
	}

	/**
	 * @param array{value:string,expires:int} $cookie
	 * @return array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}
	 */
	private function buildEtherpadSessionCookie(array $cookie): array {
		$cookieDomain = $this->resolveCookieDomain();
		return [
			'name' => 'sessionID',
			'value' => $cookie['value'],
			'expires' => $cookie['expires'],
			'path' => '/',
			'domain' => $cookieDomain,
			'secure' => true,
			'same_site' => $this->sameSiteMode(),
			// Up to Etherpad 2.7.3 the pad app reads `sessionID` itself, in
			// the browser — HttpOnly there would lock the user out of every
			// protected pad. From 3.0.0 the server takes it out of the
			// socket.io handshake and the browser never needs to see it, so
			// the cookie can be kept away from any script on the page.
			'http_only' => $this->releasePolicy->supportsHttpOnlySessionCookie(),
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

	/**
	 * How far the session cookie may travel.
	 *
	 * `Lax` by default, and it costs nothing in the ordinary chain:
	 * Nextcloud and Etherpad have to share a registrable domain for a
	 * protected pad to work at all — a browser rejects a `Set-Cookie` whose
	 * `Domain=` is not a suffix of the host that set it — so the pad iframe
	 * is a same-site subresource, while a foreign page framing a pad URL
	 * gets nothing. `Strict` would go further and is deliberately not used:
	 * it would also withhold the cookie from a top-level navigation, so a
	 * pad link in an email would open unauthenticated.
	 *
	 * `None` is asked for, never inferred. It is needed by exactly one
	 * deployment: a foreign site framing the embed routes, where Nextcloud
	 * authenticates the request without a cookie — proxy-injected
	 * `REMOTE_USER`, Kerberos, SAML in environment mode. A cookie policy
	 * cannot see that, and the previous attempt to work it out from the
	 * hosts involved needed a public suffix list to be right. So the admin
	 * says so.
	 *
	 * Anything else is `Lax`, and named by the connection test rather than
	 * swallowed — `strict` included, where somebody meant to harden.
	 */
	public function sameSiteMode(): string {
		return $this->readSameSite()['mode'];
	}

	/**
	 * A stored value that is none of the accepted words, or ''.
	 *
	 * Worth being able to say: `off`, `no` and `cross-site` all read as
	 * `Lax` here, and so does `strict` — where somebody meant to harden and
	 * gets the opposite. The sibling setting for HttpOnly reports the same
	 * thing for the same reason.
	 */
	public function unrecognisedSameSite(): string {
		return $this->readSameSite()['unrecognised'];
	}

	/**
	 * The stored value, read once and in one place.
	 *
	 * Public through the two methods above so the admin health check reports
	 * on the same reading rather than parsing the value again with its own
	 * default.
	 *
	 * @return array{mode:string,unrecognised:string}
	 */
	private function readSameSite(): array {
		$configured = strtolower(trim((string)$this->config->getAppValue(
			'etherpad_nextcloud',
			self::SAME_SITE_KEY,
			'lax',
		)));
		if ($configured === 'none') {
			return ['mode' => self::SAME_SITE_NONE, 'unrecognised' => ''];
		}
		if ($configured === 'lax' || $configured === '') {
			return ['mode' => self::SAME_SITE_LAX, 'unrecognised' => ''];
		}

		return ['mode' => self::SAME_SITE_LAX, 'unrecognised' => substr($configured, 0, 32)];
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

	/**
	 * The Etherpad author this user writes as, if one has been made.
	 *
	 * The mapper is `nc:<uid>`, which Etherpad stores globally — two
	 * Nextclouds pointed at one pad server share the author, and with it
	 * each other's sessions. Naming it per instance is the fix and is not
	 * done here: `syncAuthorMapping` asks for the mapper on every open, so
	 * changing its shape re-issues an author for every existing user at
	 * once, and their live sessions become invisible to the revoking this
	 * branch is for. It needs a migration, not a one-line change.
	 *
	 * Public because it is what makes revoking possible without a table of
	 * our own: Etherpad already knows which sessions belong to an author,
	 * and this is the only step between a Nextcloud uid and that answer.
	 * Empty when the user has never opened a protected pad — then there is
	 * nothing to revoke either.
	 */
	public function cachedAuthorId(string $uid): string {
		return $this->resolveCachedAuthorId($uid);
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
