<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IFilenameValidator;
use OCP\IUser;

class PadPlaceholderResolver {
	private const TOKEN_REGEX = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?::\s*([^|}]+?)\s*)?(?:\|\s*([^}]+?)\s*)?\}\}/i';
	private const DEFAULT_DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private ITimeFactory $timeFactory,
		private IFilenameValidator $filenameValidator,
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
				return $name !== '' ? $name : $this->pathSegment($user->getUID(), dropLeadingDots: false);
			case 'uid':
				// Its leading dots stay: `.anna` and `anna` are two users.
				return $sanitize
					? $this->pathSegment($user->getUID(), dropLeadingDots: false)
					: $user->getUID();
			default:
				return $original;
		}
	}

	/**
	 * A user's value as one filename segment, its words kept apart.
	 * Nextcloud refuses a name with a C0 control character; those, DEL and
	 * separators become a space, and so does what the admin forbids in a
	 * name (sanitizeFilename()). `.` and `..`, a current or parent folder,
	 * give nothing. A display name also loses its leading spaces and dots,
	 * so it names nothing hidden; a UID keeps its dots.
	 *
	 * @see https://github.com/nextcloud/server/blob/v32.0.9/lib/private/Files/FilenameValidator.php
	 */
	private function pathSegment(string $value, bool $dropLeadingDots): string {
		$cleaned = (string)preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', ' ', $value);
		$cleaned = rtrim(ltrim($cleaned, $dropLeadingDots ? ' .' : ' '), ' ');
		if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
			return '';
		}
		return trim($this->filenameValidator->sanitizeFilename($cleaned, ' '), ' ');
	}
}
