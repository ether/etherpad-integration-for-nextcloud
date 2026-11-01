<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Util\PadFileType;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PadMetadataService {
	private const NO_PAD = ['access_mode' => '', 'is_external' => false, 'pad_id' => '', 'pad_url' => '', 'public_open_url' => ''];

	public function __construct(
		private PadFileService $padFileService,
		private PathNormalizer $padPaths,
		private UserNodeResolver $userNodeResolver,
		private PadFileLockRetryService $lockRetryService,
		private EtherpadClient $etherpadClient,
		private ExternalPadExportFetcher $externalPadExportFetcher,
		private BindingService $bindingService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Looks up whether the frontmatter `pad_id` of an orphaned `.pad` file
	 * points at another `.pad` in the requester's userspace. Used to offer
	 * "Open the original" in the recovery UI when a copy was made.
	 *
	 * Authorization: the response is identical for every miss path — no
	 * binding, binding for ext.* pad ID, binding owned by another user,
	 * trashed / pending-delete binding, frontmatter unreadable. The presence
	 * of the `found: true` payload itself is therefore the only signal, and
	 * it is only emitted when the requester can already read the bound file
	 * (gated by UserNodeResolver). This means a crafted frontmatter cannot
	 * be used to probe for `.pad` files in other users' accounts.
	 */
	public function findOriginalForCopy(string $uid, int $fileId): PadOriginalLookup {
		try {
			$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		} catch (NotFoundException) {
			return new PadOriginalLookup(found: false);
		}
		if (!PadFileType::isPad($node->getName())) {
			return new PadOriginalLookup(found: false);
		}

		try {
			$padId = $this->padFileService->readPad((string)$node->getContent())->padId;
		} catch (\Throwable) {
			return new PadOriginalLookup(found: false);
		}
		if ($padId === '' || str_starts_with($padId, 'ext.')) {
			return new PadOriginalLookup(found: false);
		}

		$binding = $this->bindingService->findByPadId($padId, BindingService::STATE_ACTIVE);
		if ($binding === null) {
			return new PadOriginalLookup(found: false);
		}

		$boundFileId = $binding->fileId;
		if ($boundFileId <= 0 || $boundFileId === $fileId) {
			return new PadOriginalLookup(found: false);
		}

		try {
			$originalNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $boundFileId);
		} catch (NotFoundException) {
			return new PadOriginalLookup(found: false);
		}

		return new PadOriginalLookup(
			found: true,
			fileId: $boundFileId,
			path: $this->userNodeResolver->toUserAbsolutePath($uid, $originalNode),
		);
	}

	/**
	 * @throws NotFoundException
	 * @throws LockedException
	 */
	public function metaById(string $uid, int $fileId): PadMeta {
		$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $fileId);
		$absolutePath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		return $this->buildMeta($node, $absolutePath);
	}

	public function resolve(string $uid, int $fileId = 0, string $file = ''): PadResolution {
		$resolvedFileId = $fileId;
		if ($resolvedFileId > 0) {
			try {
				$node = $this->userNodeResolver->resolveUserFileNodeById($uid, $resolvedFileId);
				$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
			} catch (NotFoundException) {
				return new PadResolution(isPad: false, fileId: $resolvedFileId);
			}
		} else {
			$requestedPath = $this->padPaths->normalizeViewerFilePath($file);
			if ($requestedPath === '') {
				throw new \InvalidArgumentException('Invalid file path.');
			}

			try {
				$node = $this->userNodeResolver->resolveUserFileNodeByPath($uid, $requestedPath);
			} catch (NotFoundException) {
				return new PadResolution(isPad: false, path: $requestedPath);
			}

			$resolvedFileId = (int)$node->getId();
			if ($resolvedFileId <= 0) {
				return new PadResolution(isPad: false, path: $requestedPath);
			}
			$normalizedPath = $this->userNodeResolver->toUserAbsolutePath($uid, $node);
		}

		if (!PadFileType::isPad($normalizedPath)) {
			return new PadResolution(isPad: false, fileId: $resolvedFileId, path: $normalizedPath);
		}

		return $this->buildResolve($node, $resolvedFileId, $normalizedPath);
	}

	/**
	 * @throws LockedException
	 */
	private function buildMeta(File $node, string $absolutePath): PadMeta {
		$fileId = (int)$node->getId();
		if ($fileId <= 0) {
			throw new \RuntimeException('Could not resolve file ID.');
		}

		if (!PadFileType::isPad($absolutePath)) {
			return new PadMeta(
				isPad: false,
				fileId: $fileId,
				name: $node->getName(),
				path: $absolutePath,
			);
		}

		$metadata = $this->readPadMetadata($node, $fileId, true);

		return new PadMeta(
			isPad: true,
			fileId: $fileId,
			name: $node->getName(),
			path: $absolutePath,
			isPadMime: $node->getMimeType() === PadFileType::MIME,
			accessMode: $metadata['access_mode'],
			isExternal: $metadata['is_external'],
			padId: $metadata['pad_id'],
			padUrl: $metadata['pad_url'],
			publicOpenUrl: $metadata['public_open_url'],
		);
	}

	private function buildResolve(File $node, int $fileId, string $absolutePath): PadResolution {
		$metadata = $this->readPadMetadata($node, $fileId, false);

		return new PadResolution(
			isPad: true,
			fileId: $fileId,
			path: $absolutePath,
			isPadMime: $node->getMimeType() === PadFileType::MIME,
			accessMode: $metadata['access_mode'],
			isExternal: $metadata['is_external'],
			publicOpenUrl: $metadata['public_open_url'],
		);
	}

	/**
	 * What a file reports of its pad. The row says which pad that is: a copied
	 * or edited file's text cannot. A row that cannot be read stops the answer
	 * rather than pass for a file with no pad.
	 *
	 * File Access Control can leave a blocked file found without read
	 * permission. Its row must not give out what reading the file would deny.
	 *
	 * @see https://github.com/nextcloud/files_accesscontrol/blob/stable35/lib/CacheWrapper.php
	 *
	 * @return array{access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string}
	 */
	private function readPadMetadata(File $node, int $fileId, bool $retryLockedRead): array {
		if (!$node->isReadable()) {
			return self::NO_PAD;
		}
		$binding = $this->bindingService->padOfFile($fileId);
		return $binding !== null
			? $this->metadataFromRow($binding, $node)
			: $this->metadataWithoutRow($node, $fileId, $retryLockedRead);
	}

	/**
	 * Addresses as the open path has them. This asks the same question, and
	 * with the same caveat: whether the file is writable, not what the share
	 * granted. Without write permission a protected pad has no address, and a
	 * public pad's is Etherpad's read-only view or none: never the editable
	 * one, which a failed lookup must not bring back.
	 *
	 * @return array{access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string}
	 */
	private function metadataFromRow(Binding $binding, File $node): array {
		$isPublic = $binding->accessMode === BindingService::ACCESS_PUBLIC;
		$mayWrite = false;
		$padUrl = '';
		try {
			$mayWrite = $node->isUpdateable();
			if ($mayWrite) {
				$padUrl = $this->etherpadClient->buildPadUrl($binding->padId);
			} elseif ($isPublic) {
				$padUrl = $this->etherpadClient->getReadOnlyPadUrl($binding->padId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not build the pad address for metadata.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $binding->fileId,
				...SafeError::context($e),
			]);
		}

		return [
			'access_mode' => $binding->accessMode,
			'is_external' => false,
			'pad_id' => $binding->padIdFor($mayWrite),
			'pad_url' => $padUrl,
			'public_open_url' => $isPublic ? $padUrl : '',
		];
	}

	/**
	 * With no row, only text that names a pad on another server is reported,
	 * and its address only once it passes the checks.
	 *
	 * @return array{access_mode:string,is_external:bool,pad_id:string,pad_url:string,public_open_url:string}
	 */
	private function metadataWithoutRow(File $node, int $fileId, bool $retryLockedRead): array {
		$named = null;
		$url = '';
		try {
			$content = $retryLockedRead
				? $this->lockRetryService->readContentWithOpenLockRetry($node)
				: $node->getContent();
			$pad = $this->padFileService->readPad($content);
			if ($pad->isExternal) {
				$named = $pad;
				$url = $this->externalPadExportFetcher->normalizeAndValidateExternalPublicPadUrl($pad->externalPadUrl())['pad_url'];
			}
		} catch (\Throwable $e) {
			if ($retryLockedRead && $e instanceof LockedException) {
				throw $e;
			}
			$this->logger->debug('The file gave no complete pad metadata.', [
				'app' => 'etherpad_nextcloud',
				'fileId' => $fileId,
				...SafeError::context($e),
			]);
		}

		return [
			'access_mode' => $named->accessMode ?? '',
			'is_external' => $named !== null,
			'pad_id' => $named->padId ?? '',
			'pad_url' => $url,
			'public_open_url' => $url,
		];
	}
}
