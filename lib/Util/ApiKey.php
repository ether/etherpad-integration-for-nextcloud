<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Util;

/**
 * The Etherpad api key, carried so that a stack trace cannot print it.
 *
 * Nextcloud serializes an exception by walking its frames and expanding
 * each argument - scalars verbatim, objects through get_object_vars(),
 * which from outside the class yields only public properties. A private
 * one therefore serializes as `{}`, while the same value passed as a
 * string or inside an array is written to the log in full.
 *
 * Deliberately not stringable. Interpolating it where reveal() was meant
 * would then send `***` as the key and Etherpad would answer "no or
 * wrong API Key" - a credential problem that does not exist. Without it
 * the same mistake stops at the line that made it.
 */
final class ApiKey {
	private readonly string $value;

	/** Trimmed on the way in: a pasted key carries whitespace, the wire does not. */
	public function __construct(string $value) {
		$this->value = trim($value);
	}

	public function reveal(): string {
		return $this->value;
	}

	/** So a caller can fall back rather than send an empty apikey. */
	public function isEmpty(): bool {
		return $this->value === '';
	}
}
