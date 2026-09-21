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
	 *   $this->logger->warning('...', ['app' => ..., 'fileId' => $id]
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
		return [
			'error' => get_class($e),
			'error_message' => self::readable($e->getMessage(), $secrets),
			'error_origin' => self::originOf($e, $secrets),
		];
	}

	/**
	 * One message, fit for a log. Redacted before it is cut, and cut per
	 * message rather than over the assembled line: a secret straddling the
	 * cut is no longer whole for str_replace to find, and its prefix would
	 * travel on.
	 *
	 * @param list<string> $secrets
	 */
	private static function readable(string $message, array $secrets): string {
		foreach ($secrets as $secret) {
			$message = DiagnosticText::withoutSecret($message, $secret);
		}
		return DiagnosticText::shorten($message, self::MESSAGE_MAX_LENGTH);
	}

	/**
	 * The causal chain with what each link said, then the frames that led
	 * there as file, line and callee. The arguments are left out on
	 * purpose - they are the whole point.
	 *
	 * The chain matters as much as the location: this app wraps a
	 * transport failure as "Etherpad API request failed: <method>", so the
	 * outermost message alone never names the cause.
	 *
	 * And the frames come from the innermost cause, not the wrapper. A
	 * trace begins where its exception was constructed, so a wrapper's
	 * trace starts at the catch and walks back through the callers - the
	 * frames that produced the failure are only in the one thrown there.
	 *
	 * @param list<string> $secrets
	 */
	public static function originOf(\Throwable $e, array $secrets = []): string {
		$origin = [];
		// From the cause, not from $e: its message is already error_message,
		// and repeating it would spend half the line saying it twice.
		$current = $e->getPrevious();
		$innermost = $e;
		for ($link = 0; $current !== null && $link < self::CHAIN_LINKS; $link++) {
			$origin[] = get_class($current) . ' at ' . $current->getFile() . ':' . $current->getLine()
				. ' - ' . self::readable($current->getMessage(), $secrets);
			$innermost = $current;
			$current = $current->getPrevious();
		}
		foreach (array_slice($innermost->getTrace(), 0, self::TRACE_FRAMES) as $frame) {
			$origin[] = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?')
				. ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
		}
		return implode(' | ', $origin);
	}
}
