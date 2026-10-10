<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IMemcache;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Who opens a writable link to a protected pad: a visitor of their own,
 * so Etherpad shows each in a colour and under a name of their own.
 *
 * Etherpad takes the author of a session over the browser's own, so
 * visitors who opened as one author would show as one. Each gets an id,
 * kept in Nextcloud's session of the public page for this link with the
 * Etherpad author it opened as, and opens as
 * `public-share:<token>:<visitor>`: the same author while that session
 * lives, a new one in a new one.
 *
 * A visitor is cheap, though - a request without the session cookie is a
 * new one - and each makes an author, which Etherpad never deletes. So a
 * link has at most 250 visitors of their own an hour, each counted in
 * every hour they open it, and any past the count open as the link
 * itself, `public-share:<token>`: writing works the same, only the colours
 * are shared. Counting takes a memory cache, and with only a local one the
 * count holds for each web server; without one, or while it fails, a
 * visitor not yet counted this hour opens as the link.
 */
class PublicLinkVisitors {
	/** How many visitors of their own a link has an hour, with a memory cache. */
	public const PER_HOUR = 250;

	/** The name of the link's own author, which visitors past the count share. */
	public const LINK_AUTHOR_NAME = 'Public share';

	private const ID_LENGTH = 32;
	private const ID_CHARACTERS = '0123456789abcdef';

	public function __construct(
		private ISession $session,
		private PublicLinkCache $linkCache,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** Whether $uid is a link's visitor of their own, not a link or a user. */
	public static function isVisitor(string $uid): bool {
		return preg_match('/^' . preg_quote(PadSessionService::PUBLIC_LINK_UID_PREFIX, '/') . '[^:]+:[0-9a-f]{' . self::ID_LENGTH . '}$/D', $uid) === 1;
	}

	/**
	 * Who the visitor of this link opens as: themselves, or the link when
	 * it has its visitors for the hour. A visitor is given no name, so the
	 * one they set in Etherpad stays.
	 */
	public function openerFor(string $token): PublicLinkOpener {
		$link = PadSessionService::PUBLIC_LINK_UID_PREFIX . $token;
		$hour = intdiv($this->timeFactory->getTime(), 3600);
		$stored = $this->stored($token);
		// A later hour too: one written by a server whose clock is ahead.
		if ($stored !== null && $stored['hour'] >= $hour) {
			return new PublicLinkOpener($link . ':' . $stored['id'], '', $stored['author']);
		}
		if (!$this->admits($token, $hour)) {
			return new PublicLinkOpener($link, self::LINK_AUTHOR_NAME, '');
		}
		$visitor = $stored['id'] ?? $this->random->generate(self::ID_LENGTH, self::ID_CHARACTERS);
		$author = $stored['author'] ?? '';
		$this->store($token, $visitor, $hour, $author);
		return new PublicLinkOpener($link . ':' . $visitor, '', $author);
	}

	/**
	 * Remember the Etherpad author $opener opened as, so the next open of
	 * this visitor need not ask Etherpad for it. Nothing for the link.
	 */
	public function rememberAuthor(string $token, PublicLinkOpener $opener, string $authorId): void {
		$stored = $this->stored($token);
		if ($stored === null || $authorId === $stored['author'] || $opener->uid() !== PadSessionService::PUBLIC_LINK_UID_PREFIX . $token . ':' . $stored['id']) {
			return;
		}
		$this->store($token, $stored['id'], $stored['hour'], $authorId);
	}

	/**
	 * Whether the link has room for another visitor of their own this
	 * hour. Only a memory cache can count: without one, or while it fails,
	 * there is no room, and a failure is said at debug.
	 */
	private function admits(string $token, int $hour): bool {
		try {
			$cache = $this->linkCache->open('public-link-visitors');
			if (!$cache instanceof IMemcache) {
				return false;
			}
			$key = $this->linkCache->key('visitors', $token) . ':' . $hour;
			$cache->add($key, 0, 3600);
			$count = $cache->inc($key);
			if (!is_int($count)) {
				return false;
			}
			if ($count <= self::PER_HOUR) {
				return true;
			}
			// Once an hour for the link: a crowd, or a loop, is worth one line.
			if ($cache->add($key . ':said', 1, 3600)) {
				$this->logger->warning('A public link has its visitors of their own for this hour; further visitors open as the link\'s one Etherpad author until the hour is over.', [
					'app' => Application::APP_ID,
					'visitorsPerHour' => self::PER_HOUR,
				]);
			}
			return false;
		} catch (\Throwable $e) {
			$this->logger->debug('The memory cache failed, so a public link\'s visitors open as the link\'s one Etherpad author while it fails.', [
				'app' => Application::APP_ID,
				...SafeError::context($e),
			]);
			return false;
		}
	}

	/**
	 * What the session holds for this link's visitor: their id, the hour
	 * they were last counted in, and their Etherpad author if known.
	 *
	 * @return ?array{id:string,hour:int,author:string}
	 */
	private function stored(string $token): ?array {
		$value = $this->session->get($this->sessionKey($token));
		if (!is_array($value)) {
			return null;
		}
		$id = $value['id'] ?? null;
		$hour = $value['hour'] ?? null;
		$author = $value['author'] ?? null;
		if (!is_string($id) || preg_match('/^[0-9a-f]{' . self::ID_LENGTH . '}$/D', $id) !== 1 || !is_int($hour) || !is_string($author)) {
			return null;
		}
		return ['id' => $id, 'hour' => $hour, 'author' => $author];
	}

	private function store(string $token, string $visitor, int $hour, string $author): void {
		$this->session->set($this->sessionKey($token), ['id' => $visitor, 'hour' => $hour, 'author' => $author]);
	}

	private function sessionKey(string $token): string {
		return Application::APP_ID . '_visitor_' . $this->linkCache->key('visitor', $token);
	}
}
