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
 * Nextcloud expands each frame argument when it serializes an exception:
 * a string verbatim, an object through get_object_vars(), which from
 * outside yields only public properties. A private one is therefore `{}`.
 *
 * Deliberately not stringable. A __toString returning `***` would send
 * that as the key where reveal() was meant, and Etherpad would answer
 * "no or wrong API Key" - a credential problem that does not exist.
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
