<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
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
	 * One entry per group, so this is how many protected pads may be open
	 * at once before the one expiring soonest loses access. A session id
	 * is `s.` plus 16 characters, and buildSetCookieHeader percent-encodes
	 * the commas, so 25 take 25×18 + 24×3 = 522 bytes of the 4 KB a cookie
	 * may hold - a cookie on the domain Nextcloud and Etherpad share, which
	 * is how this request reads it. The pad being opened is always kept;
	 * beyond that the ones expiring last.
	 */
	public const MAX_SESSION_IDS = 25;

	/**
	 * What a public link's uid starts with: a visitor of the link opens as
	 * `public-share:<token>:<visitor>`, or as the link itself,
	 * `public-share:<token>` (PublicLinkVisitors), each its own Etherpad
	 * author, and no Nextcloud user can be called that, since a uid takes
	 * no colon.
	 */
	public const PUBLIC_LINK_UID_PREFIX = 'public-share:';

	/**
	 * How long a session an authenticated open mints stays valid. Chosen,
	 * not derived: revocation fires only on a logout, an account's deletion
	 * and a delete of the pad's file, so for most sessions this is the
	 * bound.
	 */
	public const SESSION_TTL_SECONDS = 21600;

	/** `lax` (default) or `none`; see sameSiteMode(). */
	public const SAME_SITE_KEY = 'etherpad_session_cookie_samesite';
	public const SAME_SITE_LAX = 'Lax';
	public const SAME_SITE_NONE = 'None';

	/**
	 * How many ids are accepted from the cookie; the cap above decides what
	 * survives. Any host under the shared parent domain can write this
	 * cookie, and a forged value may not decide how many ids each open
	 * compares. Twice the emit cap, so a legitimate cookie is never cut.
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
		private ITimeFactory $timeFactory,
		private PublicLinkSessions $linkSessions,
	) {
	}

	/**
	 * The pad's address and the Etherpad session cookie for $uid, and the
	 * author it opened as. A $displayName of '' gives Etherpad no name: a
	 * link's visitor sets their own. $knownAuthorId spares asking Etherpad
	 * for an author kept elsewhere - a public link's visitor's.
	 *
	 * @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string},authorId:string}
	 */
	public function createProtectedOpenContext(string $uid, string $displayName, string $padId, int $ttlSeconds = self::SESSION_TTL_SECONDS, string $knownAuthorId = ''): array {
		$groupId = $this->extractGroupId($padId);
		$safeTtlSeconds = max(60, $ttlSeconds);
		$validUntil = $this->timeFactory->getTime() + $safeTtlSeconds;
		$authorId = $knownAuthorId !== '' ? $knownAuthorId : $this->resolveCachedAuthorId($uid);
		if ($authorId !== '') {
			$authorId = $this->syncAuthorMapping($uid, $authorId, $displayName);
			try {
				return [...$this->openContextFor($uid, $authorId, $groupId, $padId, $validUntil), 'authorId' => $authorId];
			} catch (EtherpadClientException $e) {
				// Only an author Etherpad does not know is made anew; an
				// outage fails the open as it is. The kept id stays either
				// way: it is how a logout finds the user's sessions.
				if (!EtherpadErrorClassifier::isAuthorUnknown($e)) {
					throw $e;
				}
			}
		}

		$authorId = $this->etherpadClient->createAuthorIfNotExistsFor('nc:' . $uid, $displayName);
		$this->rememberAuthorId($uid, $authorId);
		$this->rememberAuthorName($uid, $displayName);
		return [...$this->openContextFor($uid, $authorId, $groupId, $padId, $validUntil), 'authorId' => $authorId];
	}

	/**
	 * A session for the pad being opened - a fresh one, or for a public
	 * link the one it made within the hour - plus the ids this browser
	 * already carries for other pads.
	 *
	 * Etherpad reads the cookie as a list and picks the entry for the pad's
	 * group, so the others have to survive the write, or a second tab's pad
	 * loses its access. Only ids the browser carried survive - an open gives
	 * back no access the user has since lost - and only those Etherpad's
	 * listing attributes to this author, at most one per group, the
	 * longest-lived. Ids it does not attribute are dropped: a public link's
	 * session, which is an author of its own, and the session of whoever
	 * used this browser before, which nothing here can tell apart.
	 *
	 * @return array{url:string,cookie:array{name:string,value:string,expires:int,path:string,domain:string,secure:bool,http_only:bool,same_site:string}}
	 */
	private function openContextFor(string $uid, string $authorId, string $groupId, string $padId, int $validUntil): array {
		// First, so an open that fails after this still leaves its author
		// for the sweep. A public link's below, once it made a session.
		$isLink = str_starts_with($uid, self::PUBLIC_LINK_UID_PREFIX);
		if (!$isLink) {
			$this->collector->noteAuthor($authorId);
		}

		$carriedSessionIds = $this->sessionIdsFromCookie();
		$sessions = $this->sessionsToAttributeWith($uid, $authorId, $carriedSessionIds);

		// A fresh session, not the one the browser carries: Etherpad checks
		// validUntil on every socket message and keeps the session id it got
		// at CLIENT_READY, so a session that expires mid-edit rejects the
		// next keystroke, and no later cookie reaches that socket.
		//
		// A public link is the exception: Etherpad keeps every session, and
		// a link opened in a loop would fill it with them. The session made
		// for the visitor - or the link, past its hour's count - within the
		// hour is handed out again as long as Etherpad confirms it, so a
		// visitor gets at least two of the three hours (PublicLinkSessions).
		if ($isLink) {
			$made = false;
			$session = $this->linkSessions->sessionFor(
				$uid,
				$authorId,
				$groupId,
				$validUntil,
				function () use ($groupId, $authorId, $validUntil, &$made): string {
					$sessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
					$made = true;
					return $sessionId;
				},
			);
			$chosenSessionId = $session['sessionId'];
			$validUntil = $session['validUntil'];
			// Only a session made adds something to collect, and this route
			// is open to anyone. A visitor's own author is collected by group
			// alone, or each would queue a sweep (ExpiredSessionCollector).
			if ($made) {
				if (!PublicLinkVisitors::isVisitor($uid)) {
					$this->collector->noteAuthor($authorId);
				}
				$this->collector->noteGroup($groupId);
			}
		} else {
			$chosenSessionId = $this->etherpadClient->createSession($groupId, $authorId, $validUntil);
		}
		if (preg_match(self::SESSION_ID_PATTERN, $chosenSessionId) !== 1) {
			// The id goes into a cookie the next open reads back: a shape
			// sessionIdsFromCookie does not accept would leave every later
			// open with one pad's session, silently.
			$this->logger->warning('Etherpad returned a session id in an unexpected shape; carrying sessions between pads will not work', [
				'app' => 'etherpad_nextcloud',
			]);
		}

		return [
			'url' => $this->etherpadClient->buildPadUrl($padId),
			'cookie' => $this->buildEtherpadSessionCookie(
				$this->cookieValueFor($chosenSessionId, $validUntil, $groupId, $carriedSessionIds, $sessions),
			),
		];
	}

	/**
	 * What the carried ids can be checked against, or an empty list when
	 * they cannot be checked at all.
	 *
	 * Not asked for when there is nothing to check: an open with an empty
	 * cookie - the first protected open of a browsing session - costs no
	 * extra round trip.
	 *
	 * Not asked for on a public share either. The link's own author, which
	 * every visitor past the hour's count shares, carries a session for
	 * every hour it was opened in for each pad, or one for every open
	 * without a memory cache, and every open would download the lot. The
	 * cost: two protected pads inside one shared folder cannot be open at
	 * once.
	 *
	 * @param list<string> $carriedSessionIds
	 * @return array<string,array{groupID:string,validUntil:int}>
	 */
	private function sessionsToAttributeWith(string $uid, string $authorId, array $carriedSessionIds): array {
		if ($carriedSessionIds === [] || !$this->shouldPersistAuthorState($uid)) {
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
				...SafeError::context($e),
			]);
			return [];
		}

		return $sessions;
	}

	/**
	 * @param list<string> $carriedSessionIds
	 * @param array<string,array{groupID:string,validUntil:int}> $sessions
	 * @return array{value:string,expires:int}
	 */
	private function cookieValueFor(
		string $chosenSessionId,
		int $validUntil,
		string $groupId,
		array $carriedSessionIds,
		array $sessions,
	): array {
		$now = $this->timeFactory->getTime();
		$carried = [];

		foreach ($carriedSessionIds as $candidate) {
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

		if ($carried === [] && $carriedSessionIds !== [] && $sessions !== []) {
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
			throw new PadFileFormatException('Protected pad ID is invalid (group prefix missing).');
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
		$maxAge = max(0, $cookie['expires'] - $this->timeFactory->getTime());
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
	 * `Lax` by default: Nextcloud and Etherpad share a registrable domain
	 * for a protected pad to work at all, so the pad iframe is a same-site
	 * subresource, while a foreign page framing a pad URL gets nothing.
	 * Not `Strict`, which would withhold the cookie from a top-level
	 * navigation too: a pad link in an email would open unauthenticated.
	 *
	 * `None` only as the admin sets it: for a foreign site framing the embed
	 * routes where Nextcloud authenticates without a cookie - proxy-injected
	 * `REMOTE_USER`, Kerberos, SAML in environment mode - which no cookie
	 * policy can see.
	 *
	 * Anything else is `Lax`, and named by the connection test - `strict`
	 * included, where somebody meant to harden.
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
	 * The stored setting parsed for the cookie and for the connection
	 * test alike, with one default (the two methods above).
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
	 * The mapper is `nc:<uid>`, which Etherpad stores globally - two
	 * Nextclouds pointed at one pad server share the author, and with it
	 * each other's sessions. Naming it per instance needs a migration:
	 * `syncAuthorMapping` asks for the mapper on every open, so a new shape
	 * gives every user a new author at once, and their live sessions drop
	 * out of a revoke's reach.
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

	/**
	 * The session ids this browser is carrying.
	 *
	 * Public so a revoke can take them first. Which sessions exist is a
	 * question for the pad server, but which one is in front of the person
	 * at this keyboard is only answerable here.
	 *
	 * @return list<string>
	 */
	public function carriedSessionIds(): array {
		return $this->sessionIdsFromCookie();
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

	private function shouldPersistAuthorState(string $uid): bool {
		return $uid !== '' && !str_starts_with($uid, self::PUBLIC_LINK_UID_PREFIX);
	}
}
