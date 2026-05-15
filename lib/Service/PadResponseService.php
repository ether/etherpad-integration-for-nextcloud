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
use OCP\IL10N;
use OCP\IURLGenerator;

class PadResponseService {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private AppConfigService $appConfigService,
		private IL10N $l10n,
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

	/** @return array<string,mixed> */
	public function metaResponse(PadMeta $meta): array {
		if (!$meta->isPad) {
			return [
				'is_pad' => false,
				'file_id' => $meta->fileId,
				'name' => $meta->name,
				'path' => $meta->path,
			];
		}
		return $this->withViewerAndEmbedUrls([
			'is_pad' => true,
			'is_pad_mime' => $meta->isPadMime,
			'file_id' => $meta->fileId,
			'name' => $meta->name,
			'path' => $meta->path,
			'access_mode' => $meta->accessMode,
			'is_external' => $meta->isExternal,
			'pad_id' => $meta->padId,
			'pad_url' => $meta->padUrl,
			'public_open_url' => $meta->publicOpenUrl,
		]);
	}

	/** @return array<string,mixed> */
	public function resolveResponse(PadResolution $resolution): array {
		if (!$resolution->isPad) {
			$payload = ['is_pad' => false];
			if ($resolution->fileId !== null) {
				$payload['file_id'] = $resolution->fileId;
			}
			if ($resolution->path !== null) {
				$payload['path'] = $resolution->path;
			}
			return $payload;
		}
		return $this->withViewerUrl([
			'is_pad' => true,
			'is_pad_mime' => $resolution->isPadMime,
			'file_id' => $resolution->fileId
				?? throw new \LogicException('PadResolution::fileId must be set when isPad is true.'),
			'path' => $resolution->path
				?? throw new \LogicException('PadResolution::path must be set when isPad is true.'),
			'access_mode' => $resolution->accessMode,
			'is_external' => $resolution->isExternal,
			'public_open_url' => $resolution->publicOpenUrl,
		]);
	}

	/** @return array<string,mixed> */
	public function originalLookupResponse(PadOriginalLookup $lookup): array {
		if (!$lookup->found) {
			return ['found' => false];
		}
		return $this->withViewerAndEmbedUrls([
			'found' => true,
			'file_id' => $lookup->fileId
				?? throw new \LogicException('PadOriginalLookup::fileId must be set when found is true.'),
			'path' => $lookup->path
				?? throw new \LogicException('PadOriginalLookup::path must be set when found is true.'),
		]);
	}

	/** @return array<string,mixed> */
	public function syncStatusResponse(PadSyncStatus $status): array {
		$payload = ['status' => $status->status];
		// inSync is intentionally emitted unconditionally: the viewer's
		// polling loop branches on `in_sync === null` to distinguish the
		// "no revision available" status (external / unavailable) from the
		// regular synced/out-of-sync states. Serializing null preserves
		// that signal.
		$payload['in_sync'] = $status->inSync;
		if ($status->snapshotRev !== null) {
			$payload['snapshot_rev'] = $status->snapshotRev;
		}
		if ($status->currentRev !== null) {
			$payload['current_rev'] = $status->currentRev;
		}
		if ($status->reason !== null) {
			$payload['reason'] = $status->reason;
		}
		return $payload;
	}

	/** @return array<string,mixed> */
	public function syncResponse(PadSyncResult $result): array {
		$payload = [
			'status' => $result->status,
			'file_id' => $result->fileId,
			'pad_id' => $result->padId,
			'external' => $result->external,
			'forced' => $result->forced,
		];
		if ($result->snapshotRev !== null) {
			$payload['snapshot_rev'] = $result->snapshotRev;
		}
		if ($result->currentRev !== null) {
			$payload['current_rev'] = $result->currentRev;
		}
		if ($result->lockRetries !== null) {
			$payload['lock_retries'] = $result->lockRetries;
		}
		if ($result->retryable) {
			$payload['retryable'] = true;
		}
		return $payload;
	}

	/** @return array<string,mixed> */
	public function initializationResponse(PadInitializationResult $result): array {
		return [
			'status' => $result->status,
			'file' => $result->file,
			'file_id' => $result->fileId,
			'pad_id' => $result->padId,
			'access_mode' => $result->accessMode,
		];
	}

	public function openResponse(PadOpenTarget $target): DataResponse {
		$payload = [
			'file' => $target->file,
			'file_id' => $target->fileId,
			'pad_id' => $target->padId,
			'access_mode' => $target->accessMode,
			'pad_url' => $target->padUrl,
			'is_external' => $target->isExternal,
			'original_pad_url' => $target->originalPadUrl,
			'snapshot_text' => $target->snapshotText,
			'snapshot_html' => $target->snapshotHtml,
			'url' => $target->url,
			'sync_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncById', ['fileId' => $target->fileId]),
			'sync_status_url' => $this->urlGenerator->linkToRoute('etherpad_nextcloud.pad.syncStatusById', ['fileId' => $target->fileId]),
			'sync_interval_seconds' => $this->appConfigService->getSyncIntervalSeconds(),
		];

		$response = new DataResponse($payload);
		if ($target->cookieHeader !== '') {
			$response->addHeader('Set-Cookie', $target->cookieHeader);
		}
		return $response;
	}

	public function bindingErrorMessage(BindingException $e): string {
		$message = trim($e->getMessage());
		if ($e instanceof MissingBindingException) {
			return $this->l10n->t('This .pad file has no matching pad in this Nextcloud.');
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
