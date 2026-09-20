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
 */
final class ApiKey {
	public function __construct(private readonly string $value) {
	}

	public function reveal(): string {
		return $this->value;
	}

	/** So an accidental interpolation cannot put the key in a message. */
	public function __toString(): string {
		return '***';
	}
}
