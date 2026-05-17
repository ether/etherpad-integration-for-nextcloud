<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class PadCreationService {
	public function __construct(
		private PadFileService $padFileService,
		private PadPathService $padPaths,
		private PadFileCreator $padFileCreator,
		private UserNodeResolver $userNodeResolver,
		private PadCreateRollbackService $rollbackService,
		private BindingService $bindingService,
		private EtherpadClient $etherpadClient,
		private PadBootstrapService $padBootstrapService,
		private PadPlaceholderResolver $placeholderResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function create(string $uid, string $file, string $accessMode): array {
		$path = $this->padPaths->normalizeCreatePath($file);
		$padId = '';
		$fileCreated = false;

		return $this->withCreateRollback(
			function () use ($uid, $path, $accessMode, &$padId, &$fileCreated): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$fileCreated = true;
				$fileId = (int)$fileNode->getId();
				if ($fileId <= 0) {
					throw new \RuntimeException('Could not resolve new file ID.');
				}
				$padId = $this->padBootstrapService->provisionPadId($accessMode);
				$padUrl = $this->etherpadClient->buildPadUrl($padId);

				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$padId,
					$accessMode,
					'',
					$padUrl
				);
				$fileNode->putContent($content);

				$this->bindingService->createBinding($fileId, $padId, $accessMode);

				return [
					'file' => $path,
					'file_id' => $fileId,
					'pad_id' => $padId,
					'access_mode' => $accessMode,
					'pad_url' => $padUrl,
				];
			},
			function () use ($uid, $path, &$padId, &$fileCreated): void {
				$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			},
			function (\Throwable $e) use ($path, $accessMode, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create hit existing binding',
						'context' => [
							'file' => $path,
							'accessMode' => $accessMode,
							'padId' => $padId,
						],
					];
				}

				return null;
			},
			function () use ($path, $accessMode, &$padId): array {
				return [
					'message' => 'Pad creation failed',
					'context' => [
						'file' => $path,
						'accessMode' => $accessMode,
						'padId' => $padId,
					],
				];
			},
		);
	}

	/**
	 * @return array{file:string,file_id:int,parent_folder_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createInParent(string $uid, int $parentFolderId, string $name, string $accessMode): array {
		$fileName = $this->padPaths->normalizeCreateFileName($name);
		$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($uid, $parentFolderId);
		if (!$parentFolder->isCreatable()) {
			throw new PadParentFolderNotWritableException('Selected parent folder is not writable.');
		}

		$padId = '';
		$fileCreated = false;
		$path = '';

		return $this->withCreateRollback(
			function () use ($uid, $parentFolder, $parentFolderId, $fileName, $accessMode, &$path, &$padId, &$fileCreated): array {
				$fileNode = $this->padFileCreator->createUserFileInFolder($parentFolder, $fileName);
				$fileCreated = true;
				$path = $this->userNodeResolver->toUserAbsolutePath($uid, $fileNode);
				$fileId = (int)$fileNode->getId();
				if ($fileId <= 0) {
					throw new \RuntimeException('Could not resolve new file ID.');
				}

				$padId = $this->padBootstrapService->provisionPadId($accessMode);
				$padUrl = $this->etherpadClient->buildPadUrl($padId);
				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$padId,
					$accessMode,
					'',
					$padUrl
				);
				$fileNode->putContent($content);
				$this->bindingService->createBinding($fileId, $padId, $accessMode);

				return [
					'file' => $path,
					'file_id' => $fileId,
					'parent_folder_id' => $parentFolderId,
					'pad_id' => $padId,
					'access_mode' => $accessMode,
					'pad_url' => $padUrl,
				];
			},
			function () use ($uid, &$path, &$padId, &$fileCreated): void {
				$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			},
			function (\Throwable $e) use ($parentFolderId, $name, $accessMode, &$path, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad creation by parent hit existing binding',
						'context' => [
							'parentFolderId' => $parentFolderId,
							'padName' => $name,
							'path' => $path,
							'accessMode' => $accessMode,
							'padId' => $padId,
						],
					];
				}

				return null;
			},
			function () use ($parentFolderId, $name, $accessMode, &$path, &$padId): array {
				return [
					'message' => 'Pad creation by parent failed',
					'context' => [
						'parentFolderId' => $parentFolderId,
						'padName' => $name,
						'path' => $path,
						'accessMode' => $accessMode,
						'padId' => $padId,
					],
				];
			},
		);
	}

	/**
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createFromUrl(string $uid, string $file, string $padUrl): array {
		$path = $this->padPaths->normalizeCreatePath($file);
		$fileCreated = false;
		$external = null;

		return $this->withCreateRollback(
			function () use ($uid, $path, $padUrl, &$external, &$fileCreated): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$fileCreated = true;
				$fileId = (int)$fileNode->getId();
				if ($fileId <= 0) {
					throw new \RuntimeException('Could not resolve new file ID.');
				}

				$external = $this->etherpadClient->normalizeAndFetchExternalPublicPadTextOrEmpty($padUrl);
				// External pads are no longer DB-bound, so the local marker only needs to
				// distinguish them from managed internal pad IDs. The canonical remote
				// identity remains pad_origin + remote_pad_id in the frontmatter.
				$externalPadId = 'ext.' . $external['pad_id'];
				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$externalPadId,
					BindingService::ACCESS_PUBLIC,
					'',
					$external['pad_url'],
					[
						'pad_origin' => $external['origin'],
						'remote_pad_id' => $external['pad_id'],
					]
				);
				$content = $this->padFileService->withExportSnapshot($content, $external['text'], '', 0, false);
				$fileNode->putContent($content);

				$result = [
					'file' => $path,
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
				return $result;
			},
			function () use ($uid, $path, &$fileCreated): void {
				$this->rollbackService->rollbackExternalCreate($uid, $path, $fileCreated);
			},
			function (\Throwable $e) use ($path, $padUrl): ?array {
				if ($e instanceof EtherpadClientException) {
					return [
						'message' => 'External pad URL validation failed',
						'context' => [
							'file' => $path,
							'padUrl' => $padUrl,
						],
					];
				}

				return null;
			},
			function () use ($path, $padUrl): array {
				return [
					'message' => 'External pad create failed',
					'context' => [
						'file' => $path,
						'padUrl' => $padUrl,
					],
				];
			},
		);
	}

	/**
	 * Materializes a new pad from an existing `.pad` source file. Reuses the
	 * same provisioning + binding pipeline as create(), but seeds the new
	 * pad with the template's body (placeholders resolved). The source can
	 * be any `.pad` in the requester's userspace — caller picks the file
	 * by id, so a custom frontend can decide what counts as a template
	 * (current folder, ancestor scan, a fixed picker, …).
	 *
	 * @return array{file:string,file_id:int,pad_id:string,access_mode:string,pad_url:string}
	 */
	public function createFromTemplate(string $uid, string $targetFile, int $templateFileId, ?IUser $user): array {
		$resolvedTargetFile = $this->placeholderResolver->apply($targetFile, $user);
		$path = $this->padPaths->normalizeCreatePath($resolvedTargetFile);

		$templateNode = $this->userNodeResolver->resolveUserFileNodeById($uid, $templateFileId);
		if (!str_ends_with(strtolower($templateNode->getName()), '.pad')) {
			throw new NotAPadFileException('Template is not a .pad file.');
		}
		$templateContent = (string)$templateNode->getContent();
		if (trim($templateContent) === '') {
			throw new \InvalidArgumentException('Template is empty.');
		}

		$parsed = $this->padFileService->parsePadFile($templateContent);
		$frontmatter = $parsed['frontmatter'];
		$meta = $this->padFileService->extractPadMetadata($frontmatter);
		$sourcePadId = (string)($meta['pad_id'] ?? '');
		if ($sourcePadId === '' || str_starts_with($sourcePadId, 'ext.')
			|| $this->padFileService->isExternalFrontmatter($frontmatter, $sourcePadId)) {
			throw new \InvalidArgumentException('External pads cannot be used as a template.');
		}

		$accessMode = (string)($meta['access_mode'] ?? BindingService::ACCESS_PROTECTED);
		if ($accessMode !== BindingService::ACCESS_PUBLIC && $accessMode !== BindingService::ACCESS_PROTECTED) {
			$accessMode = BindingService::ACCESS_PROTECTED;
		}

		$snapshot = $this->padFileService->getSnapshotPartsFromBody((string)$parsed['body']);
		$resolvedText = $this->placeholderResolver->apply($snapshot['text'], $user);
		$resolvedHtml = $this->placeholderResolver->apply($snapshot['html'], $user);

		$padId = '';
		$fileCreated = false;

		return $this->withCreateRollback(
			function () use ($uid, $path, $accessMode, $resolvedText, $resolvedHtml, &$padId, &$fileCreated): array {
				$fileNode = $this->padFileCreator->createUserFile($uid, $path);
				$fileCreated = true;
				$fileId = (int)$fileNode->getId();
				if ($fileId <= 0) {
					throw new \RuntimeException('Could not resolve new file ID.');
				}

				$padId = $this->padBootstrapService->provisionPadId($accessMode);
				$this->padBootstrapService->pushInitialSnapshot($padId, $resolvedText, $resolvedHtml);
				$padUrl = $this->etherpadClient->buildPadUrl($padId);

				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					$padId,
					$accessMode,
					$resolvedText,
					$padUrl,
				);
				$content = $this->padFileService->withExportSnapshot(
					$content,
					$resolvedText,
					$resolvedHtml,
					0,
					true,
				);
				$fileNode->putContent($content);
				$this->bindingService->createBinding($fileId, $padId, $accessMode);

				return [
					'file' => $path,
					'file_id' => $fileId,
					'pad_id' => $padId,
					'access_mode' => $accessMode,
					'pad_url' => $padUrl,
				];
			},
			function () use ($uid, $path, &$padId, &$fileCreated): void {
				$this->rollbackService->rollbackFailedCreate($uid, $path, $padId, $fileCreated);
			},
			function (\Throwable $e) use ($path, $accessMode, &$padId): ?array {
				if ($e instanceof BindingException) {
					return [
						'message' => 'Pad create-from-template hit existing binding',
						'context' => [
							'file' => $path,
							'accessMode' => $accessMode,
							'padId' => $padId,
						],
					];
				}
				return null;
			},
			function () use ($path, $accessMode, $templateFileId, &$padId): array {
				return [
					'message' => 'Pad create-from-template failed',
					'context' => [
						'file' => $path,
						'templateFileId' => $templateFileId,
						'accessMode' => $accessMode,
						'padId' => $padId,
					],
				];
			},
		);
	}

	/**
	 * @template T
	 * @param callable():T $action
	 * @param callable():void $rollback
	 * @param callable(\Throwable):?array{message:string,context:array<string,mixed>} $warningFor
	 * @param callable():array{message:string,context:array<string,mixed>} $errorFor
	 * @return T
	 */
	private function withCreateRollback(
		callable $action,
		callable $rollback,
		callable $warningFor,
		callable $errorFor,
	): mixed {
		try {
			return $action();
		} catch (\Throwable $e) {
			$warning = $warningFor($e);
			if ($warning !== null) {
				$this->logger->warning($warning['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$warning['context'],
					['exception' => $e],
				));
			} elseif (!($e instanceof PadFileAlreadyExistsException)) {
				$error = $errorFor();
				$this->logger->error($error['message'], array_merge(
					['app' => 'etherpad_nextcloud'],
					$error['context'],
					['exception' => $e],
				));
			}

			$rollback();
			throw $e;
		}
	}
}
