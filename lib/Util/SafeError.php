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
 *
 * It takes away the arguments, not the wording. Messages travel, this
 * app's own and the cause's, because a wrapper alone never names a
 * reason - and a message is written by whoever threw it, so it can carry
 * a url or a path nobody here chose. A caller that knows a value to be
 * secret says so; past that the wording is a judgement, not a guarantee.
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
	 * Values a caller knows to be secret are taken out of both halves.
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
	 * Where it was thrown, the causal chain with what each link said, and
	 * the frames that led there as file, line and callee. The arguments
	 * are left out on purpose - they are the whole point.
	 *
	 * The throw site comes first because no trace holds it: getTrace()
	 * begins at the caller of the frame that built the exception.
	 *
	 * @param list<string> $secrets
	 */
	public static function originOf(\Throwable $e, array $secrets = []): string {
		$origin = ['thrown at ' . $e->getFile() . ':' . $e->getLine()];
		// Walked whole before anything is dropped: the frames have to come
		// from the innermost cause, because a trace begins where its own
		// exception was built and a wrapper's therefore starts at the
		// catch. Capping the walk would take them from a wrapper.
		$chain = [];
		for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
			$chain[] = $cause;
		}
		$innermost = $chain === [] ? $e : $chain[array_key_last($chain)];
		// Over the cap, the head and the root are kept and the wrappers
		// between them go: those repeat this app's own phrasing, while the
		// root is the one that names a reason.
		if (count($chain) > self::CHAIN_LINKS) {
			$chain = array_merge(array_slice($chain, 0, self::CHAIN_LINKS - 1), [$innermost]);
		}
		// From the causes, not from $e: its message is already
		// error_message and its location is already above.
		foreach ($chain as $cause) {
			$origin[] = get_class($cause) . ' at ' . $cause->getFile() . ':' . $cause->getLine()
				. ' - ' . self::readable($cause->getMessage(), $secrets);
		}
		foreach (array_slice($innermost->getTrace(), 0, self::TRACE_FRAMES) as $frame) {
			$origin[] = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?')
				. ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
		}
		return implode(' | ', $origin);
	}
}
