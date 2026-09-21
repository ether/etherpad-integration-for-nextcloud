<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * Upstream wording on its way to an admin panel or a log. Both operations
 * are one-liners and both were wrong once in a way the other was not, so
 * they live here rather than beside each reader of them.
 */
class DiagnosticText {
	/** What one line of a check may occupy. */
	public const MAX_LENGTH = 160;

	/**
	 * By characters, not bytes: a cut inside a multi-byte sequence leaves
	 * invalid UTF-8, which json_encode refuses - a log then stores null and
	 * a response body can come back empty.
	 */
	public static function shorten(string $message, int $maxLength = self::MAX_LENGTH): string {
		$message = trim($message);
		return mb_strlen($message, 'UTF-8') > $maxLength
			? mb_substr($message, 0, $maxLength, 'UTF-8') . '…'
			: $message;
	}

	/**
	 * Where a url points, and nothing else. An admin-supplied address can
	 * carry credentials in its userinfo, a token in its query and a pad id
	 * in its path - and for a public pad the path is itself the
	 * permission. Callers already log the file or pad the address belongs
	 * to, so the host is what a log is missing.
	 */
	public static function hostOf(string $url): string {
		$trimmed = trim($url);
		$parts = parse_url($trimmed);
		if (is_array($parts) && isset($parts['host'])) {
			$scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
			$port = isset($parts['port']) ? ':' . $parts['port'] : '';
			return $scheme . $parts['host'] . $port;
		}

		// No host to read - which is the shape the one caller logging this
		// is reporting on, so saying nothing would erase the diagnosis.
		// Enough of it to recognise, without the parts that carry secrets.
		$withoutSecrets = preg_replace('/^[^\/@]*@/', '', (string)preg_replace('/[?#].*$/', '', $trimmed));
		return $withoutSecrets === '' ? '(no host)' : self::shorten($withoutSecrets, 60);
	}

	/**
	 * Takes one known secret out of text nobody can vouch for, in the
	 * spellings a request carries it in. Call it before shortening: a
	 * secret straddling the cut is no longer whole to be matched, and its
	 * prefix would travel on.
	 *
	 * No length floor. str_replace knows no word boundaries, so a short
	 * secret can blank an unrelated word - which is visible, unlike the
	 * secret it would otherwise leave in place.
	 */
	public static function withoutSecret(string $text, string $secret): string {
		$secret = trim($secret);
		if ($secret === '') {
			return $text;
		}
		return str_replace([$secret, rawurlencode($secret), urlencode($secret)], '***', $text);
	}
}
