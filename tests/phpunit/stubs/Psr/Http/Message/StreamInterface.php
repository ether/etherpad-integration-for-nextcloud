<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace Psr\Http\Message;

/**
 * Stands in for psr/http-message 2.0 where the suite runs without Composer's
 * autoloader - a PHPUnit PHAR on the declared PHP floor, where the installed
 * toolchain cannot run.
 *
 * Every signature is copied from the real interface, parameter and return
 * types included. An emptier stub would let BoundedSinkStream satisfy a
 * contract it does not actually meet, which is the opposite of what running
 * these tests is for.
 */
if (!interface_exists(StreamInterface::class)) {
	interface StreamInterface {
		public function __toString(): string;

		public function close(): void;

		public function detach();

		public function getSize(): ?int;

		public function tell(): int;

		public function eof(): bool;

		public function isSeekable(): bool;

		public function seek(int $offset, int $whence = SEEK_SET): void;

		public function rewind(): void;

		public function isWritable(): bool;

		public function write(string $string): int;

		public function isReadable(): bool;

		public function read(int $length): string;

		public function getContents(): string;

		public function getMetadata(?string $key = null);
	}
}
