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
	 * Resolve placeholders for use inside a target filename or path. Display
	 * name and UID are stripped of `/`, `\`, NUL and leading dots so a
	 * free-form display name can't smuggle path-traversal segments into the
	 * resolved path. Use this when the resolved string is fed into a path
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
		// Pin to UTC so resolver output is deterministic across server timezones —
		// otherwise `strtotime`/`date` follow `date_default_timezone_get()` and
		// "today" / "next monday" drift by ±1 day on western-tz hosts.
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
	 * Strip characters that could break out of a filename segment when the
	 * resolved value is interpolated into a user-supplied path (e.g. a target
	 * file name in `createFromTemplate`). Display names are free-form and may
	 * contain `/`, `\`, NUL or `..` — none of which belong inside a single
	 * filename segment. We collapse runs of separators to a single space so
	 * the result remains human-readable.
	 */
	private function sanitizePathLikeValue(string $value): string {
		$cleaned = str_replace(["\0", "\r", "\n", "\t"], '', $value);
		$cleaned = preg_replace('#[/\\\\]+#', ' ', $cleaned) ?? $cleaned;
		// Disallow leading dots so a display name "..foo" can't become "../foo"
		// after concatenation with separators.
		$cleaned = preg_replace('/^\.+/', '', $cleaned) ?? $cleaned;
		return trim($cleaned);
	}
}
