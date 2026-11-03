<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\IConfig;
use OCP\IUser;

class PadPlaceholderResolver {
	private const TOKEN_REGEX = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?::\s*([^|}]+?)\s*)?(?:\|\s*([^}]+?)\s*)?\}\}/i';
	private const DEFAULT_DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private ITimeFactory $timeFactory,
		private IConfig $config,
	) {
	}

	/**
	 * Resolve placeholders for use inside user-visible pad body content.
	 * Display name and UID are preserved as-is so e.g. "AC/DC" stays "AC/DC".
	 */
	public function applyForContent(string $content, ?IUser $user): string {
		return $this->applyInternal($content, $user, sanitizeUserValues: false);
	}

	/**
	 * Resolve placeholders for a target file name or path. Display name and
	 * UID pass through pathSegment(), as a free-form value must not add path
	 * segments. Use this when the resolved string is fed into a path
	 * normaliser / file-create call.
	 *
	 * @throws \InvalidArgumentException when a user leaves nothing to name a
	 *   path segment by
	 */
	public function applyForPath(string $content, ?IUser $user): string {
		return $this->applyInternal($content, $user, sanitizeUserValues: true);
	}

	private function applyInternal(string $content, ?IUser $user, bool $sanitizeUserValues): string {
		return (string)preg_replace_callback(
			self::TOKEN_REGEX,
			fn (array $match): string => $this->resolve(
				$match[1],
				$match[2] ?? '',
				$match[3] ?? '',
				$user,
				$match[0],
				$sanitizeUserValues,
			),
			$content,
		);
	}

	private function resolve(string $name, string $arg, string $format, ?IUser $user, string $original, bool $sanitizeUserValues): string {
		$normalized = strtolower($name);
		[$head, $property] = array_pad(explode('.', $normalized, 2), 2, '');
		switch ($head) {
			case 'date':
				// `date` directive uses `:` for its argument and `|` for the
				// format — a dotted property is never meaningful. Leave such
				// inputs as literal so the user can spot the typo rather than
				// silently rendering today's date.
				if ($property !== '') {
					return $original;
				}
				return $this->resolveDate($arg, $format, $original);
			case 'user':
				return $this->resolveUser($property, $user, $original, $sanitizeUserValues);
			default:
				return $original;
		}
	}

	private function resolveDate(string $arg, string $format, string $original): string {
		// UTC, so the result does not depend on the server's timezone: `today`
		// and `next monday` could otherwise differ by a day between hosts.
		$reference = $this->timeFactory->getTime();
		$expression = $arg === '' ? 'today' : $arg;
		try {
			$utc = new \DateTimeZone('UTC');
			$base = (new \DateTimeImmutable('@' . $reference))->setTimezone($utc);
			// modify() rejects an expression it cannot parse: before PHP 8.3
			// with a warning and false, from 8.3 with a
			// DateMalformedStringException. Both keep the placeholder.
			$resolved = @$base->modify($expression);
			if ($resolved === false) {
				return $original;
			}
		} catch (\Throwable) {
			return $original;
		}

		$effectiveFormat = $format === '' ? self::DEFAULT_DATE_FORMAT : $format;
		try {
			return $resolved->format($effectiveFormat);
		} catch (\Throwable) {
			return $original;
		}
	}

	private function resolveUser(string $arg, ?IUser $user, string $original, bool $sanitize): string {
		if ($user === null) {
			return $original;
		}
		switch (strtolower($arg)) {
			case '':
				if (!$sanitize) {
					return $user->getDisplayName();
				}
				// A display name that leaves nothing gives way to the UID: an
				// empty value would drop a folder or name a bare `.pad`.
				$name = $this->pathSegment($user->getDisplayName(), dropLeadingDots: true);
				return $name !== '' ? $name : $this->uidSegment($user);
			case 'uid':
				return $sanitize ? $this->uidSegment($user) : $user->getUID();
			default:
				return $original;
		}
	}

	/**
	 * The UID as one segment. Its leading dots stay: `.anna` and `anna` are
	 * two users. One that leaves nothing refuses the path rather than drop a
	 * folder from it.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function uidSegment(IUser $user): string {
		$segment = $this->pathSegment($user->getUID(), dropLeadingDots: false);
		if ($segment === '') {
			throw new \InvalidArgumentException('The user leaves nothing to name a path segment by.');
		}
		return $segment;
	}

	/**
	 * A user's value as one filename segment, its words kept apart.
	 * Nextcloud refuses a name with a C0 control character or a character
	 * it forbids; those, DEL and separators become a space before the trims,
	 * so none of them can shield a dot from those. `.` and `..`, a
	 * current or parent folder, give nothing. A display name also loses its
	 * leading spaces and dots, so it names nothing hidden; a UID keeps its
	 * dots.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/FilenameValidator.php
	 */
	private function pathSegment(string $value, bool $dropLeadingDots): string {
		// A forbidden character becomes a NUL first, so a run of them and of
		// control characters turns into one space.
		$cleaned = str_replace($this->forbiddenCharacters(), "\0", $value);
		$cleaned = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $cleaned);
		$cleaned = rtrim(ltrim($cleaned, $dropLeadingDots ? ' .' : ' '), ' ');
		return $cleaned === '.' || $cleaned === '..' ? '' : $cleaned;
	}

	/**
	 * What Nextcloud forbids in a name: the separators and what the admin
	 * lists, under the current key and its predecessor. Read here, as the
	 * public IFilenameValidator answers only for a whole name and this is a
	 * part of one.
	 *
	 * @return list<string>
	 */
	private function forbiddenCharacters(): array {
		$characters = str_split(Constants::FILENAME_INVALID_CHARS);
		foreach (['forbidden_filename_characters', 'forbidden_chars'] as $key) {
			array_push($characters, ...self::strings($this->config->getSystemValue($key, [])));
		}
		return $characters;
	}

	/**
	 * The strings of a list in the system config: only a string names a
	 * character.
	 *
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
