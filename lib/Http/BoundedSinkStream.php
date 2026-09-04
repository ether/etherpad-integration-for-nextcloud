<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Http;

use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use Psr\Http\Message\StreamInterface;

/**
 * A response body that refuses to grow past a limit, as it arrives.
 *
 * Passed to the HTTP client as `sink`, so the transfer itself is what
 * stops: cURL calls this on every chunk, and a throw here aborts the
 * request. Checking the size after the client is done would be too late —
 * Nextcloud hard-wires Guzzle's `CurlHandler`, which ignores the `stream`
 * option and buffers the whole body into a temp stream first.
 */
class BoundedSinkStream implements StreamInterface {
	private string $buffer = '';

	public function __construct(
		private int $maxBytes,
	) {
	}

	public function write(string $string): int {
		if (strlen($this->buffer) + strlen($string) > $this->maxBytes) {
			throw new EtherpadTooLargeException('Etherpad API response exceeds ' . $this->maxBytes . ' bytes.');
		}
		$this->buffer .= $string;

		return strlen($string);
	}

	public function __toString(): string {
		return $this->buffer;
	}

	public function getContents(): string {
		return $this->buffer;
	}

	public function getSize(): ?int {
		return strlen($this->buffer);
	}

	public function close(): void {
	}

	public function detach() {
		return null;
	}

	public function tell(): int {
		return strlen($this->buffer);
	}

	public function eof(): bool {
		return true;
	}

	public function isSeekable(): bool {
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void {
	}

	public function rewind(): void {
	}

	public function isWritable(): bool {
		return true;
	}

	public function isReadable(): bool {
		return true;
	}

	public function read(int $length): string {
		return substr($this->buffer, 0, $length);
	}

	/** @return array<string,mixed>|mixed|null */
	public function getMetadata(?string $key = null) {
		return $key === null ? [] : null;
	}
}
