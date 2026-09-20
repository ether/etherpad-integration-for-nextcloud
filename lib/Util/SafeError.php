<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * What a log may know about a failure.
 *
 * Handing an exception object to the logger hands Nextcloud every stack
 * frame's arguments, and almost everything this app passes around is
 * either a credential or a document: an api key, a session cookie, a
 * share token, a whole pad. A frame far below the failure is printed
 * just the same, so a successful pad open can write out its session
 * because a probe behind it failed.
 *
 * This is the shape that replaces it. `getTraceAsString()` is no
 * alternative: it prints each argument truncated to fifteen characters,
 * which is plenty of a credential.
 */
final class SafeError {
	/** Enough to place a failure, short enough to stay one log entry. */
	private const TRACE_FRAMES = 12;

	/** The same bound for the other half of the line. */
	private const CHAIN_LINKS = 6;

	/** Long enough for an upstream sentence, short enough for a log. */
	private const MESSAGE_MAX_LENGTH = 400;

	/**
	 * Log context for a failure: what it was, what it said, where it came
	 * from. Merge it into the caller's own keys.
	 *
	 *   $this->logger->warning('...', ['app' => ..., 'padId' => $id]
	 *       + SafeError::context($e));
	 *
	 * Values a caller knows to be secret are taken out of both. Upstream
	 * wording is nothing to rely on: that Etherpad answers "sessionID does
	 * not exist" rather than quoting the id is its choice, not ours.
	 *
	 * @param list<string> $secrets
	 * @return array{error: string, error_message: string, error_origin: string}
	 */
	public static function context(\Throwable $e, array $secrets = []): array {
		$scrub = static function (string $text) use ($secrets): string {
			foreach ($secrets as $secret) {
				$text = DiagnosticText::withoutSecret($text, $secret);
			}
			return $text;
		};

		return [
			'error' => get_class($e),
			'error_message' => $scrub(DiagnosticText::shorten($e->getMessage(), self::MESSAGE_MAX_LENGTH)),
			'error_origin' => $scrub(self::originOf($e)),
		];
	}

	/**
	 * The causal chain with what each link said, then the frames that led
	 * there as file, line and callee. The arguments are left out on
	 * purpose - they are the whole point.
	 *
	 * The chain matters as much as the location: this app wraps a
	 * transport failure as "Etherpad API request failed: <method>", so the
	 * outermost message alone never names the cause.
	 */
	public static function originOf(\Throwable $e): string {
		$origin = [];
		$current = $e;
		for ($link = 0; $current !== null && $link < self::CHAIN_LINKS; $link++) {
			$origin[] = get_class($current) . ' at ' . $current->getFile() . ':' . $current->getLine()
				. ' - ' . DiagnosticText::shorten($current->getMessage(), self::MESSAGE_MAX_LENGTH);
			$current = $current->getPrevious();
		}
		foreach (array_slice($e->getTrace(), 0, self::TRACE_FRAMES) as $frame) {
			$origin[] = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?')
				. ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
		}
		return implode(' | ', $origin);
	}
}
