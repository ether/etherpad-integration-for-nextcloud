<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCP\Files\File;

/**
 * Writes `ext.<remote-id>` frontmatter (with a snapshot of the remote pad's
 * text) into an existing `.pad` file. Used by both `PadCreationService`'s
 * `createFromUrl` (where the file was just created) and
 * `PadLegacyMigrationService`'s cross-origin branch (where the file already
 * existed as a legacy `[InternetShortcut]`).
 *
 * Lives in its own service to keep `PadCreationService` and
 * `PadLegacyMigrationService` from forming a constructor cycle with
 * `PadBootstrapService` — the seeding logic only needs
 * `ExternalPadExportFetcher` and `PadFileService`, never the bootstrap path.
 */
class ExternalPadSeeder {
	public function __construct(
		private PadFileService $padFileService,
		private ExternalPadExportFetcher $externalPadExportFetcher,
	) {
	}

	/**
	 * Fetch the remote export and build the document, without writing it.
	 * Lets callers re-resolve and check their target after the remote fetch.
	 *
	 * @return array{content:string,result:array{file_id:int,pad_id:string,access_mode:string,pad_url:string,snapshot_warning_code?:string}}
	 */
	public function prepare(int $fileId, string $padUrl): array {
		$external = $this->externalPadExportFetcher->normalizeAndFetchExternalPublicPadTextOrEmpty($padUrl);
		// External pads aren't DB-bound (we don't own their lifecycle), so the
		// local `ext.*` pad-id is just a marker distinguishing them from
		// managed internal IDs. Canonical remote identity lives in the
		// pad_origin + remote_pad_id frontmatter extras.
		$externalPadId = 'ext.' . $external['pad_id'];
		$content = $this->padFileService->buildInitialDocument(
			$fileId,
			$externalPadId,
			BindingService::ACCESS_PUBLIC,
			$external['text'],
			$external['pad_url'],
			[
				'pad_origin' => $external['origin'],
				'remote_pad_id' => $external['pad_id'],
			],
			snapshotRev: 0,
		);

		$result = [
			'file_id' => $fileId,
			'pad_id' => $externalPadId,
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => $external['pad_url'],
		];
		if (!empty($external['snapshot_unavailable'])) {
			// The pad URL itself validated, but the public-text export
			// endpoint refused to serve content (404). Common causes:
			// the remote Etherpad has authentication on /p/<id>/export,
			// or the pad is restricted despite a public-looking URL.
			// We keep the file (the viewer can still load the pad
			// directly through the iframe) and surface a stable code
			// the frontend translates into a toast.
			$result['snapshot_warning_code'] = 'remote_export_unavailable';
		}

		return ['content' => $content, 'result' => $result];
	}

	/**
	 * Prepare and write through the supplied node without re-resolving or checking it.
	 *
	 * @return array{file_id:int,pad_id:string,access_mode:string,pad_url:string,snapshot_warning_code?:string}
	 */
	public function seed(File $file, int $fileId, string $padUrl): array {
		$prepared = $this->prepare($fileId, $padUrl);
		$file->putContent($prepared['content']);

		return $prepared['result'];
	}
}
