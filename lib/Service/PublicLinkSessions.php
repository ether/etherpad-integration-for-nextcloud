<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Util\EtherpadErrorClassifier;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * The Etherpad session a public link hands out, kept for a while so that
 * an open does not make one each time: Etherpad keeps every session until
 * it is deleted.
 *
 * A link opens as a visitor of its own, or as the link itself
 * (PublicLinkVisitors). A session Etherpad confirms is handed out again to
 * the same opener for the same pad for up to an hour; one it does not
 * confirm is replaced sooner, and opens that miss the cache at once make
 * one each. With only a local cache this holds for each web server on its
 * own; without any, every open makes a session.
 *
 * The cache only points; Etherpad decides. A kept session is handed out
 * again only once Etherpad confirms that it still exists - a session
 * taken away when the file went to the trash does not - that it is the
 * opener's author's for this pad's group, and that it runs at least as long
 * as a new one would, less the time it is kept. Etherpad turns away the
 * next keystroke of an editor whose session has run out, so a session is
 * kept for an hour at most, and for a third of its lifetime where that is
 * shorter: a visitor always gets two thirds of it.
 *
 * The key is the Etherpad address, the uid opened as - which carries the
 * token - and the group, under PublicLinkCache's HMAC.
 */
class PublicLinkSessions {
	/** How long a session is handed out again at most, after it was made. */
	public const REUSE_SECONDS = 3600;

	public function __construct(
		private PublicLinkCache $linkCache,
		private EtherpadClient $etherpadClient,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The link's session for this group: the one kept, as Etherpad
	 * confirms it, or one $create makes, then kept for the next opens.
	 *
	 * @param string $link the uid the open is made as, `public-share:<token>:<visitor>` or `public-share:<token>`
	 * @param int $validUntil when a session made now runs out
	 * @param callable(): string $create makes a session running until $validUntil
	 * @return array{sessionId:string,validUntil:int}
	 */
	public function sessionFor(string $link, string $authorId, string $groupId, int $validUntil, callable $create): array {
		$window = min(self::REUSE_SECONDS, intdiv($validUntil - $this->timeFactory->getTime(), 3));
		$cache = $window > 0 ? $this->cache() : null;
		if ($cache === null) {
			return ['sessionId' => $create(), 'validUntil' => $validUntil];
		}

		$key = $this->key($link, $groupId);
		$kept = $this->read($cache, $key);
		$unconfirmed = null;
		if ($kept !== '') {
			try {
				$session = $this->etherpadClient->getSessionInfo($kept);
				if ($session !== null
					&& $session['groupID'] === $groupId
					&& $session['authorID'] === $authorId
					&& $session['validUntil'] >= $validUntil - $window) {
					return ['sessionId' => $kept, 'validUntil' => $session['validUntil']];
				}
			} catch (EtherpadClientException $e) {
				// After a check that timed out, making one would only add a
				// second timeout.
				if (EtherpadClientException::isEtherpadUnreachable($e) && EtherpadErrorClassifier::isTimeout($e)) {
					throw $e;
				}
				$unconfirmed = $e;
			}
		}

		$sessionId = $create();
		if ($unconfirmed !== null) {
			// Said, since a kept session that cannot be confirmed is replaced
			// on every open. A session that cannot be made fails above.
			$this->logger->warning('Could not confirm the kept Etherpad session of a public link; the open made a new one.', [
				'app' => Application::APP_ID,
				...SafeError::context($unconfirmed),
			]);
		}
		$this->write($cache, $key, $sessionId, $window);
		return ['sessionId' => $sessionId, 'validUntil' => $validUntil];
	}

	/**
	 * A cache that fails - Redis gone, say, which can already throw while
	 * it is set up - is one that keeps nothing, and fails no open.
	 */
	private function cache(): ?ICache {
		try {
			return $this->linkCache->open('public-link-sessions');
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
			return null;
		}
	}

	private function read(ICache $cache, string $key): string {
		try {
			return self::asString($cache->get($key));
		} catch (\Throwable $e) {
			$this->cacheFailed($e);
			return '';
		}
	}

	private static function asString(mixed $value): string {
		return is_string($value) ? $value : '';
	}

	private function write(ICache $cache, string $key, string $sessionId, int $window): void {
		try {
			$cache->set($key, $sessionId, $window);
		} catch (\Throwable $e) {
			// The session is made and handed out; the next open makes another.
			$this->cacheFailed($e);
		}
	}

	/**
	 * Said, since a link then makes a session an open again, which nothing
	 * else would show. Once per failing call, which is once or twice an
	 * open; neither the token nor a session id is in it.
	 */
	private function cacheFailed(\Throwable $e): void {
		$this->logger->warning('The memory cache failed, so a public link\'s Etherpad session is not kept; each open makes one while it fails.', [
			'app' => Application::APP_ID,
			...SafeError::context($e),
		]);
	}

	private function key(string $link, string $groupId): string {
		return $this->linkCache->key($this->etherpadClient->configuredApiHost(), $link, $groupId);
	}
}
