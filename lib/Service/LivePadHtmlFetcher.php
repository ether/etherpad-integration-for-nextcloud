<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\EtherpadClientException;

/**
 * Loads what a pad says right now, for the read-only views.
 *
 * Own pads come over the configured API, foreign ones over their public
 * export, and both go through the same sanitizer under the same size
 * ceiling so the two paths cannot drift apart.
 *
 * Callers settle *who may read the file*; what the file may point at is
 * settled here.
 */
class LivePadHtmlFetcher {
	public function __construct(
		private EtherpadClient $etherpadClient,
		private ExternalPadExportFetcher $externalPadExportFetcher,
		private SnapshotHtmlSanitizer $htmlSanitizer,
		private BindingService $bindingService,
	) {
	}

	/**
	 * The pad this `.pad` file names, as it reads at this moment.
	 *
	 * The binding check lives here, not at each call site: it is what stops
	 * an edited `.pad` file from pointing this app's API key at somebody
	 * else's pad, and the signed-in and public paths must not be able to
	 * disagree about it.
	 */
	public function fetchForPadFile(ParsedPadFile $pad, int $fileId): LivePadHtml {
		if ($pad->isExternal) {
			if ($pad->accessMode !== BindingService::ACCESS_PUBLIC) {
				throw new EtherpadClientException('External pad metadata requires public access_mode.');
			}
			if ($pad->padUrl === '') {
				throw new EtherpadClientException('External pad URL metadata is missing or invalid.');
			}

			return $this->toPayload($this->externalPadExportFetcher->fetchExternalPublicPadHtml($pad->padUrl));
		}

		$this->bindingService->assertConsistentMapping($fileId, $pad->padId, $pad->accessMode);

		return $this->toPayload($this->etherpadClient->getHTMLForPreview($pad->padId));
	}

	private function toPayload(string $html): LivePadHtml {
		$sanitized = $this->htmlSanitizer->sanitize($html);
		// Etherpad pads out empty lines with `&nbsp;`, so a plain trim
		// would call a blank pad non-empty. Whitespace is whitespace here,
		// whichever way it is written.
		$textOnly = html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$textOnly = (string)preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $textOnly);

		return new LivePadHtml($sanitized, $textOnly === '');
	}
}
