<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

/**
 * Loads what a pad says right now, for the read-only views.
 *
 * Own pads come over the configured API, foreign ones over their public
 * export. Both answers go through the same sanitizer, and both are read
 * under the same size ceiling, so the two paths cannot drift apart in what
 * they let through or in how much of it.
 *
 * Callers must have settled access first — nothing here checks it.
 */
class LivePadHtmlFetcher {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private ExternalPadExportFetcher $externalPadExportFetcher,
		private SnapshotHtmlSanitizer $htmlSanitizer,
	) {
	}

	public function fetchInternal(string $padId): LivePadHtml {
		return $this->toPayload($this->etherpadClient->getHTMLForPreview($padId));
	}

	public function fetchExternal(string $padUrl): LivePadHtml {
		return $this->toPayload($this->externalPadExportFetcher->normalizeAndFetchExternalPublicPadHtml($padUrl)['html']);
	}

	private function toPayload(string $html): LivePadHtml {
		$sanitized = $this->htmlSanitizer->sanitize($html);
		// Etherpad pads out empty lines with `&nbsp;`, so the plain trim
		// would call a blank pad non-empty. Whitespace is whitespace here,
		// whichever way it is written.
		$textOnly = html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$textOnly = (string)preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $textOnly);

		return new LivePadHtml($sanitized, $textOnly === '');
	}
}
