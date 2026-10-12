<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * What one attempt at an external pad's export receives: its body, up to a
 * limit, and its Content-Type. cURL hands it each header line and each
 * chunk of the body as they arrive. The body is a pad's text, so it is
 * kept in a private field, out of what a trace shows of this object.
 */
class ExportAttemptSink {
	private string $body = '';
	private string $contentType = '';
	private bool $tooLarge = false;

	public function __construct(
		private int $maxBytes,
	) {
	}

	public function body(): string {
		return $this->body;
	}

	public function contentType(): string {
		return $this->contentType;
	}

	/** Whether the body went past the limit, and the transfer was stopped there. */
	public function tooLarge(): bool {
		return $this->tooLarge;
	}

	/** @param mixed $_handle cURL's handle, not needed here */
	public function header(mixed $_handle, string $line): int {
		$trimmed = trim($line);
		if ($trimmed !== '' && stripos($trimmed, 'Content-Type:') === 0) {
			$this->contentType = trim(substr($trimmed, strlen('Content-Type:')));
		}
		return strlen($line);
	}

	/**
	 * Fewer bytes taken than given stops the transfer: cURL then fails the
	 * request, and tooLarge() says why.
	 *
	 * @param mixed $_handle cURL's handle, not needed here
	 */
	public function chunk(mixed $_handle, string $chunk): int {
		if (strlen($this->body) + strlen($chunk) > $this->maxBytes) {
			$this->tooLarge = true;
			return 0;
		}
		$this->body .= $chunk;
		return strlen($chunk);
	}
}
