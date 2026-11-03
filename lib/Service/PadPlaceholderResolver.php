<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

class PadPlaceholderResolver {
	private const TOKEN_REGEX = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?::\s*([^|}]+?)\s*)?(?:\|\s*([^}]+?)\s*)?\}\}/i';
	private const DEFAULT_DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private ITimeFactory $timeFactory,
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
	 * UID pass through sanitizePathLikeValue(), as a free-form display name
	 * must not add path segments. Use this when the resolved string is fed
	 * into a path normaliser / file-create call.
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
				$value = $user->getDisplayName();
				break;
			case 'uid':
				$value = $user->getUID();
				break;
			default:
				return $original;
		}
		return $sanitize ? $this->sanitizePathLikeValue($value) : $value;
	}

	/**
	 * Keeps a display name or UID to one filename segment when it goes into a
	 * user-supplied path (e.g. a target file name in `createFromTemplate`): NUL,
	 * tabs and line breaks go, runs of separators become a space so the result
	 * stays readable, and leading spaces and dots go together.
	 */
	private function sanitizePathLikeValue(string $value): string {
		$cleaned = str_replace(["\0", "\r", "\n", "\t"], '', $value);
		$cleaned = preg_replace('#[/\\\\]+#', ' ', $cleaned) ?? $cleaned;
		// A display name of `..` would otherwise become a parent segment in a
		// path that goes on after the placeholder, and PathNormalizer refuses
		// the path then. Together, as a separator turned space can stand
		// before the dots.
		$cleaned = preg_replace('/^[ .]+/', '', $cleaned) ?? $cleaned;
		return trim($cleaned);
	}
}
