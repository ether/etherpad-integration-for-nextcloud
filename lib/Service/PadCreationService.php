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
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
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
				$content = $this->padFileService->buildInitialDocument(
					$fileId,
					'ext.' . $external['pad_id'],
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

				return [
					'file' => $path,
					'file_id' => $fileId,
					'pad_id' => 'ext.' . $external['pad_id'],
					'access_mode' => BindingService::ACCESS_PUBLIC,
					'pad_url' => $external['pad_url'],
				];
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
