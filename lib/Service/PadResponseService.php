<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IURLGenerator;

class PadResponseService {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private AppConfigService $appConfigService,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function withViewerUrl(array $data): array {
		$data['viewer_url'] = $this->buildFilesViewerUrl((int)$data['file_id'], (string)($data['file'] ?? $data['path']));
		return $data;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function withViewerAndEmbedUrls(array $data): array {
		$data = $this->withViewerUrl($data);
		$data['embed_url'] = $this->buildEmbedUrl((int)$data['file_id']);
		return $data;
	}

	/** @param array<string,mixed> $data */
	public function lifecycleResponse(array $data): DataResponse {
		$status = ($data['status'] ?? '') === LifecycleService::RESULT_SKIPPED
			? Http::STATUS_CONFLICT
			: Http::STATUS_OK;
		return new DataResponse($data, $status);
	}

	/** @param array<string,mixed> $data */
	public function openResponse(array $data): DataResponse {
		$cookieHeader = (string)($data['cookie_header'] ?? '');
		unset($data['cookie_header']);

		$fileId = (int)$data['file_id'];
		$data['sync_url'] = $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncById', ['fileId' => $fileId]);
		$data['sync_status_url'] = $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncStatusById', ['fileId' => $fileId]);
		$data['sync_interval_seconds'] = $this->appConfigService->getSyncIntervalSeconds();

		$response = new DataResponse($data);
		if ($cookieHeader !== '') {
			$response->addHeader('Set-Cookie', $cookieHeader);
		}
		return $response;
	}

	public function bindingErrorMessage(BindingException $e): string {
		$message = trim($e->getMessage());
		if ($e instanceof MissingBindingException) {
			return 'This .pad file has no matching pad in this Nextcloud.';
		}
		return $message;
	}

	private function buildFilesViewerUrl(int $fileId, string $absolutePath): string {
		$dir = dirname($absolutePath);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		}
		// `files.view.index` resolves to '/apps/files'; the canonical URL
		// the Files app routes to a specific file is
		// `/apps/files/{view}/{fileid}` with `files` as the default view.
		$base = rtrim($this->urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/files/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}

	private function buildEmbedUrl(int $fileId): string {
		return $this->urlGenerator->linkToRoute('etherpad_nextcloud.embed.showById', ['fileId' => $fileId]);
	}
}
