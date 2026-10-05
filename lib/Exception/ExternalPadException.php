<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * A pad on another server that could not be linked or read: the link is
 * not one this instance allows, that server did not answer, or it has no
 * such pad. Not this instance's Etherpad failing, and nothing its admin
 * can mend; the reason says what was wrong with the link.
 *
 * The message is for the log, in English and with what the far side said.
 * The reason is what the user reads: a code the error mapper puts into a
 * translated sentence, since neither the parsed file nor the fetcher has a
 * language to speak.
 */
class ExternalPadException extends EtherpadClientException {
	/** Not an address at all, or not an https one. */
	public const INVALID_URL = 'invalid_url';
	/** An address with a user name or password in it. */
	public const CREDENTIALS_IN_URL = 'credentials_in_url';
	/** An address that does not end in `/p/` and a pad's name. */
	public const NOT_A_PAD_URL = 'not_a_pad_url';
	/** A group pad, or a file that says the pad is protected: only public pads are linked. */
	public const NOT_PUBLIC = 'not_public';
	/** A file that names a pad on another server without a link to it. */
	public const NO_URL = 'no_url';
	/** Pads on other servers switched off on this instance. */
	public const DISABLED = 'disabled';
	/** A server the allowlist does not name. */
	public const NOT_ALLOWED = 'not_allowed';
	/** A local host, or an address in a private or reserved range. */
	public const LOCAL_ADDRESS = 'local_address';
	/** A host name that resolves to nothing. */
	public const UNRESOLVED = 'unresolved';
	/** PHP without cURL: this instance cannot ask another server at all. */
	public const NO_CURL = 'no_curl';
	/** No answer from the other server. */
	public const UNREACHABLE = 'unreachable';
	/** A server whose certificate this instance does not trust: not something a later try mends. */
	public const UNTRUSTED_CERTIFICATE = 'untrusted_certificate';
	/** An answer that sends the request elsewhere, usually to a sign-in page. */
	public const REDIRECTED = 'redirected';
	/** An answer with another HTTP error: ExternalPadHttpErrorException, which carries the status. */
	public const HTTP_ERROR = 'http_error';
	/** No such pad there, or none it lets be exported. */
	public const NOT_FOUND = 'not_found';
	/** An answer that is not the pad's content: another kind of document, or none said. */
	public const UNEXPECTED_ANSWER = 'unexpected_answer';

	/** @param self::* $reason */
	public function __construct(
		string $message,
		private readonly string $reason,
		?\Throwable $previous = null,
	) {
		// The status belongs to the sentence: one without it would read
		// "an error ()".
		if ($reason === self::HTTP_ERROR && !$this instanceof ExternalPadHttpErrorException) {
			throw new \LogicException('An HTTP error is an ExternalPadHttpErrorException, with its status.');
		}
		parent::__construct($message, 0, $previous);
	}

	/** @return self::* */
	public function reason(): string {
		/** @var self::* */
		return $this->reason;
	}

	/** Not this instance's Etherpad. */
	public function meansEtherpadUnreachable(): bool {
		return false;
	}
}
